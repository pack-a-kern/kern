<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Settings\AiProviderSettingsServiceTest;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Kern\Audit\Models\AuditEvent;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\Services\AiProviderSettingsService;

/**
 * `settings.updated_by` and `audit_events.actor_id` are native Postgres `uuid` columns with real
 * foreign keys to `users.id` — every actor id stand-in below must be a real, persisted `User`
 * row, per `docs/AGENTS.md` §7b's exact warned-about trap.
 */
function fakeUserId(): string
{
    return User::factory()->create()->id;
}

it('is off with nothing to connect to until an administrator saves a provider', function (): void {
    // No environment variable can set any of these any more: the defaults are constants.
    $view = app(AiProviderSettingsService::class)->currentView();

    expect($view->enabled)->toBeFalse()
        ->and($view->provider)->toBeNull()
        ->and($view->baseUrl)->toBeNull()
        ->and($view->model)->toBeNull()
        ->and($view->apiKeySet)->toBeFalse()
        ->and($view->updatedAt)->toBeNull();
});

it('never returns the api key itself, only whether one is set and its last four characters', function (): void {
    $service = app(AiProviderSettingsService::class);
    $service->replaceConnection('https://api.openai.example', 'sk-abcdEFGH1234', fakeUserId());

    $view = $service->currentView();

    expect($view->apiKeySet)->toBeTrue()
        ->and($view->apiKeyLastFour)->toBe('1234')
        ->and($view->toArray())->not->toHaveKey('api_key')
        ->and(json_encode($view->toArray(), JSON_THROW_ON_ERROR))->not->toContain('sk-abcdEFGH1234');
});

it('replaces the address and the key together', function (): void {
    $service = app(AiProviderSettingsService::class);
    $service->replaceConnection('https://api.openai.example', 'sk-original-key', fakeUserId());

    $view = $service->replaceConnection('https://openrouter.ai/api', 'sk-replacement', fakeUserId());

    expect($view->baseUrl)->toBe('https://openrouter.ai/api')
        ->and($view->apiKeyLastFour)->toBe('ment');
});

it('never keeps the old key for a new address: no key given means no key', function (): void {
    // The leak this method exists to close: a new address with the stored key carried over sent that
    // key wherever the address pointed.
    $service = app(AiProviderSettingsService::class);
    $service->replaceConnection('https://api.openai.example', 'sk-original-key', fakeUserId());

    $view = $service->replaceConnection('http://10.73.4.5:11434', null, fakeUserId());

    expect($view->baseUrl)->toBe('http://10.73.4.5:11434')
        ->and($view->apiKeySet)->toBeFalse();
});

it('writes exactly one system-chain audit event per replacement, never naming the secret value', function (): void {
    $actorId = fakeUserId();
    app(AiProviderSettingsService::class)->replaceConnection('https://api.openai.example', 'sk-must-never-appear-in-audit', $actorId);

    $event = AuditEvent::query()->where('event_type', 'AiProviderConnectionReplaced')->sole();

    expect($event->subject_id)->toBeNull()
        ->and($event->chain_key)->toBe(AuditChainService::SYSTEM_CHAIN_KEY)
        ->and($event->actor_id)->toBe($actorId)
        ->and($event->payload_hash)->not->toContain('sk-must-never-appear-in-audit');
});

it('never logs the plaintext api key anywhere while replacing the connection', function (): void {
    /** @var list<string> $loggedMessages */
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = $event->message . json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $secret = 'sk-absolutely-must-not-be-logged';
    $service = app(AiProviderSettingsService::class);
    $service->replaceConnection('https://api.openai.example', $secret, fakeUserId());
    $service->replaceConnection('https://api.openai.example', null, fakeUserId());

    // A single assertion over the joined log output, not a foreach — an empty $loggedMessages
    // array (the expected, correct outcome: nothing was logged at all) must still exercise a real
    // assertion, or PHPUnit correctly marks this test "risky" for asserting nothing.
    expect(implode("\n", $loggedMessages))->not->toContain($secret);
});
