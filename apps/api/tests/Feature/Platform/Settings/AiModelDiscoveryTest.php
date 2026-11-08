<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Settings\Services\AiProviderCatalogue;
use Kern\Platform\Settings\Services\FetchAiProviderModels;
use Kern\Platform\Settings\Services\TestAiProviderConnection;

it('lists models from an OpenAI-compatible provider', function (): void {
    Http::fake([
        'https://api.openai.com/v1/models' => Http::response([
            'data' => [['id' => 'gpt-4o-mini'], ['id' => 'gpt-4o']],
        ]),
    ]);

    $result = app(FetchAiProviderModels::class)('openai', 'https://api.openai.com', 'sk-test');

    expect($result->ok)->toBeTrue()
        ->and($result->models)->toBe(['gpt-4o', 'gpt-4o-mini']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test'));
});

it('lists locally pulled models from Ollama, whose response shape differs', function (): void {
    Http::fake([
        '*/api/tags' => Http::response(['models' => [['name' => 'llama3.2:3b'], ['name' => 'qwen2.5:7b']]]),
    ]);

    $result = app(FetchAiProviderModels::class)('ollama', 'http://ollama.internal:11434', null);

    expect($result->ok)->toBeTrue()
        ->and($result->models)->toBe(['llama3.2:3b', 'qwen2.5:7b']);

    // Ollama needs no credential; sending an empty bearer would make it reject the request.
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('sends the Anthropic key and version headers, not a bearer token', function (): void {
    Http::fake(['https://api.anthropic.com/v1/models' => Http::response(['data' => [['id' => 'a-model']]])]);

    app(FetchAiProviderModels::class)('anthropic', 'https://api.anthropic.com', 'sk-ant-test');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'sk-ant-test')
        && $request->hasHeader('anthropic-version', app(AiProviderCatalogue::class)->anthropicVersion())
        && ! $request->hasHeader('Authorization'));
});

it('reports a rejected key only as failed, and writes the reason to the log', function (): void {
    // The screen must not tell a refused key from an unreachable host: whoever presses the button chose
    // the address, and the difference would say whether anything answers there.
    Http::fake(['*' => Http::response(['error' => 'nope'], 401)]);

    /** @var list<array<string, mixed>> $logged */
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->context;
    });

    expect(app(FetchAiProviderModels::class)('openai', 'https://api.openai.com', 'bad')->reason)->toBe('failed')
        ->and($logged)->toContain(['operation' => 'list-models', 'reason' => 'rejected_credentials', 'http_status' => 401]);
});

it('reports an empty model list as a failure, not as a working provider', function (): void {
    // An endpoint answering 200 with something that is not a model list — a proxy page, an Ollama
    // with nothing pulled — must not present as "connected, 0 models available".
    Http::fake(['*' => Http::response(['data' => []])]);

    expect(app(FetchAiProviderModels::class)('openai', 'https://api.openai.com', 'sk-test')->reason)
        ->toBe('failed');
});

it('refuses to guess for a provider that publishes no model list', function (): void {
    Http::fake();

    expect(app(FetchAiProviderModels::class)('nope', 'https://example.test', null)->reason)
        ->toBe('unknown_provider');

    Http::assertNothingSent();
});

it('tests Anthropic through its own model list and headers, so the preset can report a working connection', function (): void {
    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_PROVIDER, 'anthropic', null);
    $settings->set(SettingKey::AI_BASE_URL, 'https://api.anthropic.com', null);
    $settings->set(SettingKey::AI_MODEL, 'a-model', null);
    $settings->set(SettingKey::AI_API_KEY, 'sk-ant-test', null);

    Http::fake([
        'https://api.anthropic.com/v1/models' => Http::response(['data' => [['id' => 'a-model']]]),
    ]);

    expect(app(TestAiProviderConnection::class)()->toArray()['success'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/models'
        && $request->hasHeader('x-api-key', 'sk-ant-test'));
});

it('pings, in its own dialect, only a provider that publishes no model list', function (): void {
    config(['ai.providers.presets.anthropic.models_path' => null]);
    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_PROVIDER, 'anthropic', null);
    $settings->set(SettingKey::AI_BASE_URL, 'https://api.anthropic.com', null);
    $settings->set(SettingKey::AI_MODEL, 'a-model', null);
    $settings->set(SettingKey::AI_API_KEY, 'sk-ant-test', null);

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response(['content' => [['text' => 'pong']]]),
    ]);

    expect(app(TestAiProviderConnection::class)()->toArray()['success'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request->hasHeader('x-api-key', 'sk-ant-test'));
});

it('lists models from the saved address with the key saved alongside it, whichever provider is named', function (): void {
    // `provider` only shapes the request for a provider picked but not yet saved; where it goes, and
    // with which key, is the saved connection's.
    $admin = settingsAdminUser();
    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_BASE_URL, 'https://api.openai.com', $admin->id);
    $settings->set(SettingKey::AI_API_KEY, 'sk-saved', $admin->id);

    Http::fake(['*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $this->actingAs($admin)
        ->postJson('/api/v1/settings/ai/models', ['provider' => 'openai'])
        ->assertOk()
        ->assertJsonPath('models', ['gpt-4o']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/models'
        && $request->hasHeader('Authorization', 'Bearer sk-saved'));
});

it('refuses an address or key sent to the model list', function (string $field): void {
    // Each used to be a way to send the stored key to an address an administrator named.
    $admin = settingsAdminUser();
    Http::fake();

    $this->actingAs($admin)
        ->postJson('/api/v1/settings/ai/models', ['provider' => 'openai', $field => 'https://attacker.example'])
        ->assertStatus(422);

    Http::assertNothingSent();
})->with(['base_url', 'api_key']);

it('does not let an ordinary user probe arbitrary hosts through the model list endpoint', function (): void {
    $user = ordinaryUser();

    Http::fake();

    $this->actingAs($user)
        ->postJson('/api/v1/settings/ai/models', [
            'provider' => 'openai',
            'base_url' => 'http://169.254.169.254',
        ])
        ->assertForbidden();

    Http::assertNothingSent();
});

/*
 * The calls an administrator makes to the provider, at most so often per account.
 *
 * Already behind a permission; this is for the script that loops. One budget for test, list and save,
 * because each is a request to the same provider with the same key.
 */
it('limits how often one administrator may call the provider, and only that administrator', function (): void {
    config(['identity.rate_limits.operator_calls_per_user.max_attempts' => 2]);

    $admin = settingsAdminUser();
    Http::fake(['*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

    $this->actingAs($admin)->postJson('/api/v1/settings/ai/models', ['provider' => 'openai'])->assertOk();
    $this->actingAs($admin)->postJson('/api/v1/settings/ai/test-connection', ['provider' => 'openai'])->assertOk();
    $this->actingAs($admin)->postJson('/api/v1/settings/ai/models', ['provider' => 'openai'])->assertStatus(429);

    $this->actingAs(settingsAdminUser())->postJson('/api/v1/settings/ai/models', ['provider' => 'openai'])->assertOk();
});

it('serves the preset catalogue to an administrator without exposing any secret', function (): void {
    $response = $this->withoutExceptionHandling()->actingAs(settingsAdminUser())
        ->getJson('/api/v1/settings/ai/providers')
        ->assertOk();

    /** @var list<array<string, mixed>> $providers */
    $providers = $response->json('providers');

    expect($providers)->not->toBeEmpty()
        ->and(array_column($providers, 'key'))->toContain('openai', 'anthropic', 'ollama', 'custom');

    foreach ($providers as $provider) {
        expect($provider)->toHaveKeys(['key', 'label', 'base_url', 'requires_api_key', 'can_list_models', 'models']);
    }
});
