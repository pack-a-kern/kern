<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\RequestExternalDocumentExtractionSettingsTest;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Documents\ExternalAi\ExternalDocumentAiProvider;
use App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\DataTransferObjects\UpdateAiProviderSettingsInput;
use App\Domain\Settings\Services\AiProviderSettingsService;
use App\Support\KeyManagement\SubjectDekService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * `docs/handoff/041-settings-module.md` requirement #4: `RequestExternalDocumentExtraction` takes
 * its AI provider configuration from the settings store, falls back to env only when nothing has
 * been saved, and picks up a change on the very next document with **no restart** — proven here by
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
        'status' => \App\Domain\Documents\Enums\DocumentStatus::SCANNED,
    ]);
    $part = $document->parts->sole();

    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new \RuntimeException('Unable to open a memory stream.');
    }

    fwrite($stream, \Tests\Fixtures\Documents\FileFixtures::minimalPdf());
    rewind($stream);
    app(\App\Support\ObjectStore\EncryptedObjectStore::class)->put($part->objectKey(), $stream);
    fclose($stream);

    return $document->fresh(['parts']) ?? $document;
}

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
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
 * `tests/Feature/Domain/Documents/RequestExternalDocumentExtractionTest.php` already establishes
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
    app(AiProviderSettingsService::class)->update(new UpdateAiProviderSettingsInput(
        enabled: true,
        provider: 'openai',
        baseUrl: 'https://api.openai.example',
        model: 'gpt-5',
        apiKey: 'sk-first-key',
    ), fakeUserId());

    // 3. The very next call, on the same $action instance, now succeeds and forwards the
    // freshly-saved credentials.
    $action($document);
    expect($provider->received)->toHaveCount(1);
    expect($provider->received[0]->credentials->provider)->toBe('openai')
        ->and($provider->received[0]->credentials->baseUrl)->toBe('https://api.openai.example')
        ->and($provider->received[0]->credentials->model)->toBe('gpt-5')
        ->and($provider->received[0]->credentials->apiKey)->toBe('sk-first-key');

    // 4. The provider is changed again — still no restart — and the next call reflects it.
    app(AiProviderSettingsService::class)->update(new UpdateAiProviderSettingsInput(
        enabled: true,
        provider: 'anthropic',
        baseUrl: 'https://api.anthropic.example',
        model: 'test-model-x',
        apiKey: 'sk-second-key',
    ), fakeUserId());

    $action($document);
    expect($provider->received)->toHaveCount(2);
    expect($provider->received[1]->credentials->provider)->toBe('anthropic')
        ->and($provider->received[1]->credentials->baseUrl)->toBe('https://api.anthropic.example')
        ->and($provider->received[1]->credentials->apiKey)->toBe('sk-second-key');

    // 5. Disabling it again immediately refuses the next document, again with no restart.
    app(AiProviderSettingsService::class)->update(new UpdateAiProviderSettingsInput(
        enabled: false,
        provider: 'anthropic',
        baseUrl: 'https://api.anthropic.example',
        model: 'test-model-x',
        apiKey: null,
    ), fakeUserId());

    expect(fn () => $action($document))
        ->toThrow(ExternalDocumentAiProviderNotConfiguredException::class);
    expect($provider->received)->toHaveCount(2);
});

it('falls back to the env-configured provider only until a setting is explicitly saved', function (): void {
    config([
        'ai.providers.env_fallback.enabled' => true,
        'ai.providers.env_fallback.provider' => 'env-openai',
        'ai.providers.env_fallback.base_url' => 'https://env.example.test',
        'ai.providers.env_fallback.model' => 'env-model',
        'ai.providers.env_fallback.api_key' => 'sk-from-env',
    ]);

    $document = sendableDocument();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $provider = recordingProvider();
    $this->app->instance(ExternalDocumentAiProvider::class, $provider);

    $action = app(RequestExternalDocumentExtraction::class);
    $action($document);

    expect($provider->received[0]->credentials->provider)->toBe('env-openai')
        ->and($provider->received[0]->credentials->apiKey)->toBe('sk-from-env');

    // An explicit save overrides the env fallback for that field going forward — existing
    // deployments relying on env vars keep working until an admin opts into the database value.
    app(AiProviderSettingsService::class)->update(new UpdateAiProviderSettingsInput(
        enabled: true,
        provider: 'db-openai',
        baseUrl: 'https://db.example.test',
        model: 'env-model',
        apiKey: null,
    ), fakeUserId());

    $action($document);
    expect($provider->received[1]->credentials->provider)->toBe('db-openai')
        // api_key was never saved to the database, so it still falls back to env.
        ->and($provider->received[1]->credentials->apiKey)->toBe('sk-from-env');
});
