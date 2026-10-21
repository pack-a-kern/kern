<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Settings\AiSettingsHttpTest;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;

/**
 * `/api/v1/settings/ai*` — `docs/handoff/041-settings-module.md`. The API key is never returned by
 * any of these endpoints; every state-changing route requires `system.settings.manage`.
 *
 * The rule under test throughout: **the stored key only ever goes to the address it was saved with.**
 * The address and the key are written together, by one route, and nothing that calls the provider
 * accepts an address of its own. Each of those used to be a way to send the key somewhere else.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** Platform access is a real role now, not an environment allowlist — see docs/adr/0014. */
function anAdmin(): User
{
    return settingsAdminUser();
}

/** A configured provider, saved the only way it can be: address and key through the connection route. */
function savedProvider(\Tests\TestCase $test, User $admin, string $baseUrl = 'https://provider.example', ?string $key = 'sk-saved'): void
{
    $test->actingAs($admin, 'web')
        ->putJson('/api/v1/settings/ai/connection', ['base_url' => $baseUrl, 'api_key' => $key])
        ->assertOk();

    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_ENABLED, '1', $admin->id);
    $settings->set(SettingKey::AI_PROVIDER, 'openai', $admin->id);
    $settings->set(SettingKey::AI_MODEL, 'gpt-5', $admin->id);
}

it('rejects an unauthenticated caller with 401 on every route', function (): void {
    $this->putJson('/api/v1/settings/ai/connection', [])->assertUnauthorized();
    $this->postJson('/api/v1/settings/ai/test-connection', [])->assertUnauthorized();
    $this->postJson('/api/v1/settings/ai/models', [])->assertUnauthorized();
});

it('rejects an authenticated non-admin caller with 403 on every route', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->putJson('/api/v1/settings/ai/connection', [
        'base_url' => 'https://provider.example', 'api_key' => 'sk-x',
    ])->assertForbidden();
    $this->actingAs($user, 'web')->postJson('/api/v1/settings/ai/test-connection', [])->assertForbidden();
    $this->actingAs($user, 'web')->postJson('/api/v1/settings/ai/models', [])->assertForbidden();
});

it('has no route left that sets the address apart from the key', function (): void {
    // The two removed doors: a whole-configuration save that kept the stored key when none was sent,
    // and a delete that cleared the key alone.
    $admin = anAdmin();

    $this->actingAs($admin, 'web')->putJson('/api/v1/settings/ai', [
        'enabled' => true, 'provider' => 'openai', 'base_url' => 'https://elsewhere.example', 'model' => 'gpt-5',
    ])->assertNotFound();
    $this->actingAs($admin, 'web')->deleteJson('/api/v1/settings/ai/api-key')->assertNotFound();
});

it('refuses the address and the key in the general settings update', function (string $key): void {
    $admin = anAdmin();
    savedProvider($this, $admin);

    $this->actingAs($admin, 'web')
        ->putJson('/api/v1/settings/group/ai', ['settings' => [$key => 'https://elsewhere.example']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(["settings.{$key}"]);

    expect(app(SettingsRepository::class)->get(SettingKey::AI_BASE_URL))->toBe('https://provider.example');
})->with(['ai.base_url', 'ai.api_key']);

it('still saves the rest of the AI group through the general update', function (): void {
    $admin = anAdmin();

    $this->actingAs($admin, 'web')
        ->putJson('/api/v1/settings/group/ai', ['settings' => ['ai.model' => 'gpt-5-mini']])
        ->assertOk();

    expect(app(SettingsRepository::class)->get(SettingKey::AI_MODEL))->toBe('gpt-5-mini');
});

it('saves the address and the key together, and never echoes the key back', function (): void {
    $admin = anAdmin();

    $response = $this->actingAs($admin, 'web')->putJson('/api/v1/settings/ai/connection', [
        'base_url' => 'https://openrouter.ai/api',
        'api_key' => 'sk-abcdEFGH5678',
    ])->assertOk();

    expect($response->json('base_url'))->toBe('https://openrouter.ai/api')
        ->and($response->json('api_key_set'))->toBeTrue()
        ->and($response->json('api_key_last_four'))->toBe('5678')
        ->and($response->getContent())->not->toContain('sk-abcdEFGH5678');
});

it('treats an empty key as no key, never as the one already saved', function (): void {
    $admin = anAdmin();
    savedProvider($this, $admin);

    $this->actingAs($admin, 'web')
        ->putJson('/api/v1/settings/ai/connection', ['base_url' => 'http://10.73.4.5:11434', 'api_key' => ''])
        ->assertOk()
        ->assertJsonPath('api_key_set', false);
});

it('accepts an address that is a scheme, a host, a port and a plain path', function (string $address): void {
    $this->actingAs(anAdmin(), 'web')
        ->putJson('/api/v1/settings/ai/connection', ['base_url' => $address, 'api_key' => null])
        ->assertOk();
})->with([
    'a public provider' => 'https://api.openai.com',
    'a provider under a path' => 'https://openrouter.ai/api',
    'a LAN address and port' => 'http://10.73.4.5:11434',
    'the docker host' => 'http://host.docker.internal:11434',
    'a trailing slash' => 'https://api.anthropic.com/',
]);

it('refuses an address that could choose the path the request reaches', function (string $address): void {
    $this->actingAs(anAdmin(), 'web')
        ->putJson('/api/v1/settings/ai/connection', ['base_url' => $address, 'api_key' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['base_url']);
})->with([
    // The appended `/v1/chat/completions` becomes a query string, and the typed path is the one called.
    'a query' => 'http://openbao:8200/v1/sys/health?',
    'a fragment' => 'https://provider.example/#',
    'credentials' => 'https://user:secret@provider.example',
    'a parent directory' => 'https://provider.example/api/../admin',
    'percent-encoding' => 'https://provider.example/%2e%2e/admin',
    'another scheme' => 'ftp://provider.example',
    'no scheme' => 'provider.example',
    'a port out of range' => 'http://provider.example:99999',
]);

it('tests the saved address with the key saved alongside it', function (): void {
    $admin = anAdmin();
    savedProvider($this, $admin);
    Http::fake([
        'provider.example/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'pong'], 'finish_reason' => 'stop']],
        ], 200),
    ]);

    $response = $this->actingAs($admin, 'web')->postJson('/api/v1/settings/ai/test-connection')->assertOk();

    expect($response->json('success'))->toBeTrue()
        ->and($response->json('latency_ms'))->toBeInt();

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://provider.example/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer sk-saved'));
});

it('refuses an address, key or model sent to the connection test', function (string $field): void {
    $admin = anAdmin();
    savedProvider($this, $admin);
    Http::fake();

    $this->actingAs($admin, 'web')
        ->postJson('/api/v1/settings/ai/test-connection', [$field => 'https://attacker.example'])
        ->assertStatus(422);

    Http::assertNothingSent();
})->with(['base_url', 'api_key', 'model']);

it('says only that a test failed, and writes why to the log', function (): void {
    // Whoever presses the button chose the address; a reason on the screen would say whether anything
    // answers there.
    $admin = anAdmin();
    savedProvider($this, $admin);
    Http::fake(['provider.example/*' => Http::response(['error' => 'invalid api key'], 401)]);

    /** @var list<array<string, mixed>> $logged */
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->context;
    });

    $this->actingAs($admin, 'web')->postJson('/api/v1/settings/ai/test-connection')
        ->assertOk()
        ->assertExactJson(['success' => false, 'error' => 'failed']);

    expect($logged)->toContain(['operation' => 'test-connection', 'reason' => 'rejected_credentials', 'http_status' => 401]);
});

it('reports not_configured when nothing has been saved', function (): void {
    $this->actingAs(anAdmin(), 'web')->postJson('/api/v1/settings/ai/test-connection')
        ->assertOk()
        ->assertExactJson(['success' => false, 'error' => 'not_configured']);
});

it('never logs the api key while saving the connection', function (): void {
    $admin = anAdmin();
    /** @var list<string> $loggedMessages */
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = $event->message . json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $secret = 'sk-must-never-reach-a-log-line';
    $this->actingAs($admin, 'web')->putJson('/api/v1/settings/ai/connection', [
        'base_url' => 'https://api.openai.example', 'api_key' => $secret,
    ])->assertOk();

    // A single assertion over the joined log output, not a foreach — an empty $loggedMessages
    // array (the expected, correct outcome) must still exercise a real assertion, or PHPUnit
    // correctly marks this test "risky" for asserting nothing.
    expect(implode("\n", $loggedMessages))->not->toContain($secret);
});
