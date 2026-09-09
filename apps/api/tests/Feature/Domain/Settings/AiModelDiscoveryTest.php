<?php

declare(strict_types=1);

use App\Domain\Settings\Enums\SettingKey;
use App\Domain\Settings\Repositories\SettingsRepository;
use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Domain\Settings\Services\FetchAiProviderModels;
use App\Domain\Settings\Services\TestAiProviderConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Swap the OpenBao-backed KMS for the in-memory fake. Encrypting a user's fields and the stored
 * API key would otherwise make real HTTP calls to the transit engine — which `Http::fake()` would
 * intercept along with the provider traffic these tests are actually about.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

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

it('reports a rejected key distinctly from an unreachable host', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 401)]);

    expect(app(FetchAiProviderModels::class)('openai', 'https://api.openai.com', 'bad')->reason)
        ->toBe('authentication_failed');
});

it('reports an empty model list as a failure, not as a working provider', function (): void {
    // An endpoint answering 200 with something that is not a model list — a proxy page, an Ollama
    // with nothing pulled — must not present as "connected, 0 models available".
    Http::fake(['*' => Http::response(['data' => []])]);

    expect(app(FetchAiProviderModels::class)('openai', 'https://api.openai.com', 'sk-test')->reason)
        ->toBe('no_models');
});

it('refuses to guess for a provider that publishes no model list', function (): void {
    Http::fake();

    expect(app(FetchAiProviderModels::class)('nope', 'https://example.test', null)->reason)
        ->toBe('unknown_provider');

    Http::assertNothingSent();
});

it('pings Anthropic on /v1/messages, so the preset can actually report a working connection', function (): void {
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

it('still pings OpenAI-compatible providers on /v1/chat/completions', function (): void {
    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_PROVIDER, 'openai', null);
    $settings->set(SettingKey::AI_BASE_URL, 'https://api.openai.com', null);
    $settings->set(SettingKey::AI_MODEL, 'gpt-4o-mini', null);
    $settings->set(SettingKey::AI_API_KEY, 'sk-test', null);

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'pong']]],
        ]),
    ]);

    expect(app(TestAiProviderConnection::class)()->toArray()['success'])->toBeTrue();
});

it('keeps a draft API key out of the request URL when listing models', function (): void {
    $admin = settingsAdminUser();

    Http::fake(['*' => Http::response(['data' => [['id' => 'gpt-4o']]])]);


    $this->actingAs($admin)
        ->postJson('/api/v1/settings/ai/models', [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com',
            'api_key' => 'sk-secret-draft',
        ])
        ->assertOk()
        ->assertJsonPath('models', ['gpt-4o']);

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'sk-secret-draft'));
});

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
