<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\RequestExternalDocumentExtractionSettingsTest;

use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use Kern\Documents\ExternalAi\ExternalDocumentAiProvider;
use Kern\Documents\ExternalAi\RequestExternalDocumentExtraction;
use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Settings\Services\AiProviderSettingsService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;

/**
 * `docs/handoff/041-settings-module.md` requirement #4: `RequestExternalDocumentExtraction` takes
 * its AI provider configuration from the settings store, with the configured defaults only for a
 * field never saved — and never the default key beside a saved address — and picks up a change on the very next document with **no restart** — proven here by
 * calling the very same, already-resolved `RequestExternalDocumentExtraction` instance twice in
 * one process, with a settings change in between, exactly as an admin saving the settings screen
 * while the application keeps running would produce.
 */
/**
 * A document with bytes the action can actually send.
 *
 * The action reads the document's own parts now rather than being handed text, so a factory row
 * with no stored object has nothing to send and is refused before a provider is ever reached.
 */
function sendableDocument(): Document
{
    $document = Document::factory()->part(['mime_type' => 'application/pdf'])->create([
        'status' => \Kern\Documents\Enums\DocumentStatus::SCANNED,
    ]);
    $part = $document->parts->sole();

    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new \RuntimeException('Unable to open a memory stream.');
    }

    fwrite($stream, \Kern\Documents\Testing\FileFixtures::minimalPdf());
    rewind($stream);
    app(\Kern\Documents\ObjectStore\EncryptedObjectStore::class)->put($part->objectKey(), $stream);
    fclose($stream);

    return $document->fresh(['parts']) ?? $document;
}

/** What the settings screen does: the connection through its dialog, the rest through the group save. */
function saveAiProvider(bool $enabled, string $provider, string $baseUrl, string $model, ?string $apiKey): void
{
    $actor = fakeUserId();
    app(AiProviderSettingsService::class)->replaceConnection($baseUrl, $apiKey, $actor);

    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_ENABLED, $enabled ? '1' : '0', $actor);
    $settings->set(SettingKey::AI_PROVIDER, $provider, $actor);
    $settings->set(SettingKey::AI_MODEL, $model, $actor);
}

beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/**
 * `settings.updated_by` and `Kern\Audit\Models\AuditEvent::actor_id` are native Postgres
 * `uuid` columns with real foreign keys to `users.id` — every actor id stand-in below must be a
 * real, persisted `User` row, per `docs/AGENTS.md` §7b's exact warned-about trap.
 */
function fakeUserId(): string
{
    return User::factory()->create()->id;
}

/**
 * A test double that records every `DocumentAiRequest` it receives instead of calling a real
 * provider — the same anonymous-class-implementing-the-interface pattern
 * `tests/Feature/Documents/RequestExternalDocumentExtractionTest.php` already establishes
 * for this interface.
 *
 * @return object{received: list<DocumentAiRequest>}&ExternalDocumentAiProvider
 */
function recordingProvider(): object
{
    return new class () implements ExternalDocumentAiProvider {
        /** @var list<DocumentAiRequest> */
        public array $received = [];

        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            $this->received[] = $request;

            return new DocumentAiResponse('acme-ai', 'acme-extractor', '1.0.0', ['field' => 'value'], '0.9000', DocumentType::RECEIPT);
        }
    };
}

it('refuses to call the provider while ai.enabled is false, the default', function (): void {
    $document = sendableDocument();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $action = app(RequestExternalDocumentExtraction::class);

    expect(fn () => $action($document))
        ->toThrow(ExternalDocumentAiProviderNotConfiguredException::class, 'ai.enabled=false');
});

it('picks up a settings change on the very next call, with no restart, no re-resolution needed', function (): void {
    $document = sendableDocument();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $provider = recordingProvider();
    $this->app->instance(ExternalDocumentAiProvider::class, $provider);

    // Same, already-resolved action instance for both calls — nothing here is re-booted or
    // re-resolved between the two calls, simulating the process staying up across an admin's
    // settings save.
    $action = app(RequestExternalDocumentExtraction::class);

    // 1. Disabled — refused, exactly like a fresh deployment before anyone has touched settings.
    expect(fn () => $action($document))
        ->toThrow(ExternalDocumentAiProviderNotConfiguredException::class);
    expect($provider->received)->toBeEmpty();

    // 2. An administrator saves settings through the same API path a real request would use —
    // no restart, no cache to bust.
    saveAiProvider(true, 'openai', 'https://api.openai.example', 'gpt-5', 'sk-first-key');

    // 3. The very next call, on the same $action instance, now succeeds and forwards the
    // freshly-saved credentials.
    $action($document);
    expect($provider->received)->toHaveCount(1);
    expect($provider->received[0]->credentials->provider)->toBe('openai')
        ->and($provider->received[0]->credentials->baseUrl)->toBe('https://api.openai.example')
        ->and($provider->received[0]->credentials->model)->toBe('gpt-5')
        ->and($provider->received[0]->credentials->apiKey)->toBe('sk-first-key');

    // 4. The provider is changed again — still no restart — and the next call reflects it.
    saveAiProvider(true, 'anthropic', 'https://api.anthropic.example', 'test-model-x', 'sk-second-key');

    $action($document);
    expect($provider->received)->toHaveCount(2);
    expect($provider->received[1]->credentials->provider)->toBe('anthropic')
        ->and($provider->received[1]->credentials->baseUrl)->toBe('https://api.anthropic.example')
        ->and($provider->received[1]->credentials->apiKey)->toBe('sk-second-key');

    // 5. Disabling it again immediately refuses the next document, again with no restart.
    saveAiProvider(false, 'anthropic', 'https://api.anthropic.example', 'test-model-x', null);

    expect(fn () => $action($document))
        ->toThrow(ExternalDocumentAiProviderNotConfiguredException::class);
    expect($provider->received)->toHaveCount(2);
});

it('never pairs the default key with a saved address', function (): void {
    // The leak this rule closes: a key from one source travelling with an address from the other. Here
    // the defaults stand in for the environment variables that used to feed them.
    config([
        'ai.providers.defaults.enabled' => true,
        'ai.providers.defaults.provider' => 'default-openai',
        'ai.providers.defaults.base_url' => 'https://default.example.test',
        'ai.providers.defaults.model' => 'default-model',
        'ai.providers.defaults.api_key' => 'sk-default',
    ]);

    $document = sendableDocument();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $provider = recordingProvider();
    $this->app->instance(ExternalDocumentAiProvider::class, $provider);

    $action = app(RequestExternalDocumentExtraction::class);
    $action($document);

    expect($provider->received[0]->credentials->baseUrl)->toBe('https://default.example.test')
        ->and($provider->received[0]->credentials->apiKey)->toBe('sk-default');

    // A new address saved with no key: the default key must not go with it.
    app(AiProviderSettingsService::class)->replaceConnection('https://db.example.test', null, fakeUserId());

    $action($document);
    expect($provider->received[1]->credentials->baseUrl)->toBe('https://db.example.test')
        ->and($provider->received[1]->credentials->apiKey)->toBeNull();
});
