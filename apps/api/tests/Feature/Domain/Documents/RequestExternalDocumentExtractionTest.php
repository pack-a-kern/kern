<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\RequestExternalDocumentExtractionTest;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\Consent\Exceptions\AiConsentRequiredException;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Documents\ExternalAi\ExternalDocumentAiProvider;
use App\Domain\Documents\ExternalAi\NullDocumentAiProvider;
use App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction;
use App\Domain\Documents\Models\Document;
use App\Support\KeyManagement\SubjectDekService;
use Kern\Audit\Models\AuditEvent;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * ADR 0005's ordering, exercised directly at the action level (`docs/handoff/
 * 022-document-pipeline.md`): consent gate first, then the (currently unbound) provider seam,
 * and an audit event written only for a send that actually happened.
 *
 * `ai.enabled` defaults to `false` (`docs/handoff/041-settings-module.md` — fail closed), which
 * would make every call in this file hit the new `disabledBySettings()` gate before ever reaching
 * the assertions below that this file already existed to make. Forcing it on via the env-fallback
 * config path (no `settings` row needed) keeps this file testing exactly what it tested before
 * that gate existed — see `tests/Feature/Domain/Documents/RequestExternalDocumentExtractionSettingsTest.php`
 * for the settings-gating behaviour itself.
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
    config(['ai.providers.env_fallback.enabled' => true]);

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/**
 * How many "a document was sent to an external AI" events exist for this document.
 *
 * The question these tests ask is whether *this send* was recorded, which is not the same as
 * whether the audit table is empty — other legitimate events (a consent grant, another test's
 * committed rows) live there too.
 */
function sendEvents(Document $document): int
{
    return AuditEvent::query()
        ->where('event_type', 'ExternalDocumentAiSendCompleted')
        ->where('resource_id', $document->id)
        ->count();
}

it('is bound to NullDocumentAiProvider by default — no real provider is chosen yet', function (): void {
    expect(app(ExternalDocumentAiProvider::class)::class)->toBe(NullDocumentAiProvider::class);
});

it('refuses to call any provider when the subject has not consented, and writes no audit event', function (): void {
    $document = sendableDocument();

    $this->app->instance(ExternalDocumentAiProvider::class, new class () implements ExternalDocumentAiProvider {
        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            throw new RuntimeException('must never be called without consent');
        }
    });

    $action = app(RequestExternalDocumentExtraction::class);

    expect(fn () => $action($document))->toThrow(AiConsentRequiredException::class);
    expect(sendEvents($document))->toBe(0);
});

it('propagates "provider not configured" once consent exists, and writes no audit event', function (): void {
    $document = sendableDocument();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $action = app(RequestExternalDocumentExtraction::class);

    expect(fn () => $action($document))
        ->toThrow(ExternalDocumentAiProviderNotConfiguredException::class);
    // Scoped to the send, not a count of the whole table: granting consent above is itself an
    // audited act, so a bare `count() === 0` asserts that nothing at all ever happened on this
    // installation — which is a different, and false, claim.
    expect(sendEvents($document))->toBe(0);
});

it('writes exactly one audit event, naming the document and provider, only after a real send succeeds', function (): void {
    $document = sendableDocument();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $this->app->instance(ExternalDocumentAiProvider::class, new class () implements ExternalDocumentAiProvider {
        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            return new DocumentAiResponse('acme-ai', 'acme-extractor', '1.0.0', ['field' => 'value'], '0.8800', DocumentType::RECEIPT);
        }
    });

    $extraction = app(RequestExternalDocumentExtraction::class)($document);

    expect($extraction->model_provider)->toBe('acme-ai');

    $event = AuditEvent::query()->where('event_type', 'ExternalDocumentAiSendCompleted')->sole();
    expect($event->subject_id)->toBe($document->tax_subject_id)
        ->and($event->resource_id)->toBe($document->id);
});
