<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Settings\AiProviderSettingsServiceTest;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Kern\Audit\Models\AuditEvent;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\DataTransferObjects\UpdateAiProviderSettingsInput;
use Kern\Platform\Settings\Services\AiProviderSettingsService;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

/**
 * `settings.updated_by` and `audit_events.actor_id` are native Postgres `uuid` columns with real
 * foreign keys to `users.id` — every actor id stand-in below must be a real, persisted `User`
 * row, per `docs/AGENTS.md` §7b's exact warned-about trap.
 */
function fakeUserId(): string
{
    return User::factory()->create()->id;
}

it('falls back to env config when no setting has ever been saved', function (): void {
    config([
        'ai.providers.env_fallback.enabled' => true,
        'ai.providers.env_fallback.provider' => 'env-provider',
        'ai.providers.env_fallback.base_url' => 'https://env.example.test',
        'ai.providers.env_fallback.model' => 'env-model',
        'ai.providers.env_fallback.api_key' => 'sk-from-env',
    ]);

    $view = app(AiProviderSettingsService::class)->currentView();

    expect($view->enabled)->toBeTrue()
        ->and($view->provider)->toBe('env-provider')
        ->and($view->baseUrl)->toBe('https://env.example.test')
        ->and($view->model)->toBe('env-model')
        ->and($view->apiKeySet)->toBeTrue()
        ->and($view->apiKeyLastFour)->toBe('-env')
        ->and($view->updatedAt)->toBeNull()
        ->and($view->updatedBy)->toBeNull();
});

it('never returns the api key itself, only whether one is set and its last four characters', function (): void {
    $service = app(AiProviderSettingsService::class);
    $service->update(new UpdateAiProviderSettingsInput(
        enabled: true,
        provider: 'openai',
        baseUrl: 'https://api.openai.example',
        model: 'gpt-5',
        apiKey: 'sk-abcdEFGH1234',
    ), fakeUserId());

    $view = $service->currentView();

    expect($view->apiKeySet)->toBeTrue()
        ->and($view->apiKeyLastFour)->toBe('1234')
        ->and($view->toArray())->not->toHaveKey('api_key')
        ->and(json_encode($view->toArray(), JSON_THROW_ON_ERROR))->not->toContain('sk-abcdEFGH1234');
});

it('a saved database value overrides the env fallback once set, per field', function (): void {
    config(['ai.providers.env_fallback.model' => 'env-model']);

    app(AiProviderSettingsService::class)->update(new UpdateAiProviderSettingsInput(
        enabled: true,
        provider: 'openai',
        baseUrl: 'https://api.openai.example',
        model: 'db-model',
        apiKey: null,
    ), fakeUserId());

    expect(app(AiProviderSettingsService::class)->currentView()->model)->toBe('db-model');
});

it('leaves the stored api key unchanged when an update omits it', function (): void {
    $service = app(AiProviderSettingsService::class);
    $service->update(new UpdateAiProviderSettingsInput(true, 'openai', 'https://api.openai.example', 'gpt-5', 'sk-original-key'), fakeUserId());
    $service->update(new UpdateAiProviderSettingsInput(true, 'openai', 'https://api.openai.example', 'gpt-5', null), fakeUserId());

    expect($service->currentView()->apiKeyLastFour)->toBe('-key');
});

it('clears the api key without touching the other fields', function (): void {
    $service = app(AiProviderSettingsService::class);
    $service->update(new UpdateAiProviderSettingsInput(true, 'openai', 'https://api.openai.example', 'gpt-5', 'sk-original-key'), fakeUserId());

    $view = $service->clearApiKey(fakeUserId());

    expect($view->apiKeySet)->toBeFalse()
        ->and($view->apiKeyLastFour)->toBeNull()
        ->and($view->provider)->toBe('openai')
        ->and($view->model)->toBe('gpt-5');
});

it('writes exactly one system-chain audit event per update, never naming the secret value', function (): void {
    $actorId = fakeUserId();
    app(AiProviderSettingsService::class)->update(new UpdateAiProviderSettingsInput(
        enabled: true,
        provider: 'openai',
        baseUrl: 'https://api.openai.example',
        model: 'gpt-5',
        apiKey: 'sk-must-never-appear-in-audit',
    ), $actorId);

    $event = AuditEvent::query()->where('event_type', 'AiProviderSettingsUpdated')->sole();

    expect($event->subject_id)->toBeNull()
        ->and($event->chain_key)->toBe(AuditChainService::SYSTEM_CHAIN_KEY)
        ->and($event->actor_id)->toBe($actorId)
        ->and($event->payload_hash)->not->toContain('sk-must-never-appear-in-audit');
});

it('writes an audit event when the api key is cleared', function (): void {
    $actorId = fakeUserId();
    app(AiProviderSettingsService::class)->clearApiKey($actorId);

    expect(AuditEvent::query()->where('event_type', 'AiApiKeySettingCleared')->where('actor_id', $actorId)->exists())->toBeTrue();
});

it('never logs the plaintext api key anywhere while updating settings', function (): void {
    /** @var list<string> $loggedMessages */
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = $event->message . json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $secret = 'sk-absolutely-must-not-be-logged';
    $actorId = fakeUserId();
    $service = app(AiProviderSettingsService::class);
    $service->update(new UpdateAiProviderSettingsInput(true, 'openai', 'https://api.openai.example', 'gpt-5', $secret), $actorId);
    $service->clearApiKey($actorId);

    // A single assertion over the joined log output, not a foreach — an empty $loggedMessages
    // array (the expected, correct outcome: nothing was logged at all) must still exercise a real
    // assertion, or PHPUnit correctly marks this test "risky" for asserting nothing.
    expect(implode("\n", $loggedMessages))->not->toContain($secret);
});
