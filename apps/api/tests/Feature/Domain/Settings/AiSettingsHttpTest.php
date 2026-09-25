<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Settings\AiSettingsHttpTest;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * `/api/v1/settings/ai*` — `docs/handoff/041-settings-module.md`. The API key is never returned by
 * any of these endpoints; every state-changing route requires `settings-admin`.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/** Platform access is a real role now, not an environment allowlist — see docs/adr/0014. */
function anAdmin(): User
{
    return settingsAdminUser();
}

it('rejects an unauthenticated caller with 401 on every route', function (): void {
    $this->getJson('/api/v1/settings/ai')->assertUnauthorized();
    $this->putJson('/api/v1/settings/ai', [])->assertUnauthorized();
    $this->deleteJson('/api/v1/settings/ai/api-key')->assertUnauthorized();
    $this->postJson('/api/v1/settings/ai/test-connection', [])->assertUnauthorized();
});

it('rejects an authenticated non-admin caller with 403 on every route', function (): void {
    // A user with no platform role at all.
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->getJson('/api/v1/settings/ai')->assertForbidden();
    $this->actingAs($user, 'web')->putJson('/api/v1/settings/ai', [
        'enabled' => true, 'provider' => 'openai', 'base_url' => 'https://api.openai.example', 'model' => 'gpt-5',
    ])->assertForbidden();
    $this->actingAs($user, 'web')->deleteJson('/api/v1/settings/ai/api-key')->assertForbidden();
    $this->actingAs($user, 'web')->postJson('/api/v1/settings/ai/test-connection', [])->assertForbidden();
});

it('lets a settings admin read the current (empty) configuration', function (): void {
    $admin = anAdmin();

    $response = $this->actingAs($admin, 'web')->getJson('/api/v1/settings/ai')->assertOk();

    expect($response->json())->toMatchArray([
        'enabled' => false,
        'provider' => null,
        'base_url' => null,
        'model' => null,
        'api_key_set' => false,
        'api_key_last_four' => null,
    ]);
});

it('lets a settings admin save the configuration, and never echoes the api key back', function (): void {
    $admin = anAdmin();

    $response = $this->actingAs($admin, 'web')->putJson('/api/v1/settings/ai', [
        'enabled' => true,
        'provider' => 'openai',
        'base_url' => 'https://api.openai.example',
        'model' => 'gpt-5',
        'api_key' => 'sk-brand-new-key-9999',
    ])->assertOk();

    expect($response->json())->toMatchArray([
        'enabled' => true,
        'provider' => 'openai',
        'base_url' => 'https://api.openai.example',
        'model' => 'gpt-5',
        'api_key_set' => true,
        'api_key_last_four' => '9999',
    ]);
    expect($response->getContent())->not->toContain('sk-brand-new-key-9999');
});

it('422s an update missing a required field', function (): void {
    $admin = anAdmin();

    $this->actingAs($admin, 'web')
        ->putJson('/api/v1/settings/ai', ['enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['provider', 'base_url', 'model']);
});

it('lets a settings admin clear the api key independently of the other fields', function (): void {
    $admin = anAdmin();
    $this->actingAs($admin, 'web')->putJson('/api/v1/settings/ai', [
        'enabled' => true, 'provider' => 'openai', 'base_url' => 'https://api.openai.example',
        'model' => 'gpt-5', 'api_key' => 'sk-to-be-cleared',
    ])->assertOk();

    $response = $this->actingAs($admin, 'web')->deleteJson('/api/v1/settings/ai/api-key')->assertOk();

    expect($response->json('api_key_set'))->toBeFalse()
        ->and($response->json('provider'))->toBe('openai');
});

it('reports a successful test-connection call against a fake provider response', function (): void {
    $admin = anAdmin();
    Http::fake([
        'provider.example/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'pong'], 'finish_reason' => 'stop']],
        ], 200),
    ]);

    $response = $this->actingAs($admin, 'web')->postJson('/api/v1/settings/ai/test-connection', [
        'base_url' => 'https://provider.example',
        'model' => 'gpt-5',
        'api_key' => 'sk-test',
    ])->assertOk();

    expect($response->json('success'))->toBeTrue()
        ->and($response->json('latency_ms'))->toBeInt();

    Http::assertSent(function (HttpRequest $request): bool {
        return $request->hasHeader('Authorization', 'Bearer sk-test');
    });
});

it('reports authentication_failed when the provider rejects the credentials', function (): void {
    $admin = anAdmin();
    Http::fake(['provider.example/*' => Http::response(['error' => 'invalid api key'], 401)]);

    $this->actingAs($admin, 'web')->postJson('/api/v1/settings/ai/test-connection', [
        'base_url' => 'https://provider.example', 'model' => 'gpt-5', 'api_key' => 'sk-wrong',
    ])->assertOk()
        ->assertJson(['success' => false, 'error' => 'authentication_failed']);
});

it('reports not_configured when nothing has been saved and no override is given', function (): void {
    $admin = anAdmin();

    $this->actingAs($admin, 'web')->postJson('/api/v1/settings/ai/test-connection', [])
        ->assertOk()
        ->assertJson(['success' => false, 'error' => 'not_configured']);
});

it('never logs the api key while saving settings through the http endpoint', function (): void {
    $admin = anAdmin();
    /** @var list<string> $loggedMessages */
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = $event->message . json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $secret = 'sk-must-never-reach-a-log-line';
    $this->actingAs($admin, 'web')->putJson('/api/v1/settings/ai', [
        'enabled' => true, 'provider' => 'openai', 'base_url' => 'https://api.openai.example',
        'model' => 'gpt-5', 'api_key' => $secret,
    ])->assertOk();

    // A single assertion over the joined log output, not a foreach — an empty $loggedMessages
    // array (the expected, correct outcome) must still exercise a real assertion, or PHPUnit
    // correctly marks this test "risky" for asserting nothing.
    expect(implode("\n", $loggedMessages))->not->toContain($secret);
});
