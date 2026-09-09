<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\ProcessScannedDocumentJobTest;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Enums\ExtractorType;
use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\Events\DocumentExtractionProposed;
use App\Domain\Documents\Events\DocumentTextExtracted;
use App\Domain\Documents\Events\DocumentTextExtractionRejected;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use App\Domain\Documents\ExternalAi\ExternalDocumentAiProvider;
use App\Domain\Documents\Extraction\Exceptions\TextExtractionUnavailableException;
use App\Domain\Documents\Jobs\ProcessScannedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentDerivative;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Support\KeyManagement\SubjectDekService;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\Fixtures\Documents\FileFixtures;

/**
 * `BLUEPRINT.md` §14.1 native-text-extraction/OCR-decision stage, and ADR 0005's consent-gated
 * external-extraction seam. Response bodies here match the real, verified
 * `docs/handoff/024-extraction-endpoint.md` contract, not handoff 022's original guess — per-page
 * `text` is `{"kind": "untrusted_document_text", "content": ...}`, `quality_score` is per page,
 * and the shared auth token is required.
 *
 * `ai.enabled` defaults to `false` (`docs/handoff/041-settings-module.md` — fail closed), which
 * would make every external-extraction scenario in this file hit the new `disabledBySettings()`
 * gate before ever reaching the assertions this file already existed to make. Forcing it on via
 * the env-fallback config path (no `settings` row needed) keeps this file testing exactly what it
 * tested before that gate existed.
 */
beforeEach(function (): void {
    config(['settings.ai.env_fallback.enabled' => true]);

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    config(['documents.text_extraction.auth_token' => 'test-shared-secret']);

    Storage::fake('s3');
});

function putOriginal(Document $document, string $content): ObjectKey
{
    $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::ORIGINAL);
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Unable to open a memory stream.');
    }

    fwrite($stream, $content);
    rewind($stream);

    app(EncryptedObjectStore::class)->put($key, $stream);
    fclose($stream);

    return $key;
}

/**
 * @param list<array{page_number: int, text: string, quality_score?: string}> $pages
 *
 * @return array<string, mixed>
 */
function extractionSuccessBody(array $pages, bool $needsOcr = false): array
{
    return [
        'page_count' => count($pages),
        'needs_ocr' => $needsOcr,
        'overall_decision' => $needsOcr ? 'ocr_required' : 'native_text_sufficient',
        'pages_needing_ocr' => [],
        'pages' => array_map(static fn (array $page): array => [
            'page_number' => $page['page_number'],
            'needs_ocr' => false,
            'quality_score' => $page['quality_score'] ?? '0.9800',
            'quality_signals' => [
                'non_whitespace_char_count' => 100,
                'total_char_count' => 120,
                'page_area_sq_inches' => 93.5,
                'char_density_per_sq_inch' => 1.07,
                'bad_char_ratio' => 0.0,
                'recognizable_word_ratio' => 0.8,
                'is_whitespace_only' => false,
            ],
            'text' => ['kind' => 'untrusted_document_text', 'content' => $page['text']],
        ], $pages),
    ];
}

it('extracts native PDF text, stores it as an encrypted derivative, and marks the document READY', function (): void {
    Event::fake([DocumentTextExtracted::class, DocumentExtractionProposed::class]);

    $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    Http::fake([
        '*/v1/documents/extract-text' => Http::response(
            extractionSuccessBody([['page_number' => 1, 'text' => 'invoice total 100 RON']]),
        ),
    ]);

    ProcessScannedDocument::dispatchSync($document->id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::READY);

    $derivative = DocumentDerivative::query()->where('document_id', $document->id)->sole();
    expect($derivative->kind)->toBe(DocumentObjectKind::EXTRACTED_TEXT);

    $derivativeKey = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::EXTRACTED_TEXT);
    $stream = app(EncryptedObjectStore::class)->get($derivativeKey);
    expect(stream_get_contents($stream))->toBe('invoice total 100 RON');
    fclose($stream);

    Event::assertDispatched(DocumentTextExtracted::class, function (DocumentTextExtracted $event): bool {
        $payload = $event->broadcastWith();

        return $payload['status'] === 'READY' && $payload['needs_ocr'] === false;
    });

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-shared-secret');
    });

    // No provider bound (NullDocumentAiProvider), no consent granted -> no extraction row at all.
    expect(DocumentExtraction::query()->count())->toBe(0);
    Event::assertNotDispatched(DocumentExtractionProposed::class);
});

it('needs OCR for an image with no native text layer, without calling the text-extraction service', function (): void {
    Event::fake([DocumentTextExtracted::class]);

    $document = Document::factory()->create(['mime_type' => 'image/png', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::pngWithDimensions(20, 20));

    Http::fake(); // any call at all fails the test via assertNothingSent below

    ProcessScannedDocument::dispatchSync($document->id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::READY);
    expect(DocumentDerivative::query()->where('document_id', $document->id)->count())->toBe(0);

    Http::assertNothingSent();

    Event::assertDispatched(DocumentTextExtracted::class, fn (DocumentTextExtracted $event): bool => $event->broadcastWith()['needs_ocr'] === true);
});

it('leaves the document at PROCESSING and lets the job fail when the text-extraction service is unreachable', function (): void {
    $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    Http::fake(['*/v1/documents/extract-text' => Http::response('', 503)]);

    expect(fn () => ProcessScannedDocument::dispatchSync($document->id))
        ->toThrow(TextExtractionUnavailableException::class);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::PROCESSING);
    expect(DocumentDerivative::query()->where('document_id', $document->id)->count())->toBe(0);
});

it('leaves the document at PROCESSING and lets the job fail, without ever sending a request, when the auth token is not configured', function (): void {
    config(['documents.text_extraction.auth_token' => '']);

    $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    Http::fake();

    expect(fn () => ProcessScannedDocument::dispatchSync($document->id))
        ->toThrow(TextExtractionUnavailableException::class);

    Http::assertNothingSent();

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::PROCESSING);
});

it('leaves the document at PROCESSING and lets the job fail when the service rejects the shared secret', function (): void {
    $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    Http::fake(['*/v1/documents/extract-text' => Http::response(['error' => 'unauthorized', 'message' => 'Invalid service authentication token.'], 401)]);

    expect(fn () => ProcessScannedDocument::dispatchSync($document->id))
        ->toThrow(TextExtractionUnavailableException::class);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::PROCESSING);
});

it(
    'rejects the document with the matching reason, broadcasts once, and never retries, for each documented "bad document" error code',
    function (int $httpStatus, string $errorCode, RejectionReason $expectedReason): void {
        Event::fake([DocumentTextExtractionRejected::class]);

        $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
        putOriginal($document, FileFixtures::minimalPdf());

        Http::fake(['*/v1/documents/extract-text' => Http::response(
            ['error' => $errorCode, 'message' => "rejected: {$errorCode}"],
            $httpStatus,
        )]);

        // Must not throw — this is a permanent rejection, not a transient failure to retry.
        ProcessScannedDocument::dispatchSync($document->id);

        $document->refresh();
        expect($document->status)->toBe(DocumentStatus::REJECTED)
            ->and($document->rejection_reason)->toBe($expectedReason);

        expect(DocumentDerivative::query()->where('document_id', $document->id)->count())->toBe(0);

        Event::assertDispatched(DocumentTextExtractionRejected::class, function (DocumentTextExtractionRejected $event) use ($expectedReason): bool {
            $payload = $event->broadcastWith();

            return $payload['status'] === 'REJECTED' && $payload['rejection_reason'] === $expectedReason->value;
        });
    },
)->with([
    'file too large (413)' => [413, 'file_too_large', RejectionReason::FILE_TOO_LARGE],
    'invalid magic bytes' => [422, 'invalid_magic_bytes', RejectionReason::INVALID_MAGIC_BYTES],
    'encrypted PDF' => [422, 'encrypted_pdf', RejectionReason::ENCRYPTED_PDF],
    'too many pages' => [422, 'too_many_pages', RejectionReason::TOO_MANY_PAGES],
    'empty document' => [422, 'empty_document', RejectionReason::EMPTY_DOCUMENT],
    // The decompression-bomb defence has no distinct code — it surfaces as one of these two
    // existing codes depending on which of the service's two defences catches it first
    // (docs/handoff/024's "Differences" #5). Both are exercised here as ordinary documented
    // codes; neither test invents a "bomb" reason that does not exist on the wire.
    'malformed PDF (wall-clock timeout victim OR a genuinely malformed file)' => [422, 'malformed_pdf', RejectionReason::MALFORMED_PDF],
    'extraction timeout (wall-clock timeout victim)' => [422, 'extraction_timeout', RejectionReason::EXTRACTION_TIMEOUT],
    'extraction failed (defensive catch-all)' => [422, 'extraction_failed', RejectionReason::EXTRACTION_FAILED],
]);

it('treats an unrecognized error code as service-unavailable rather than guessing a rejection reason', function (): void {
    $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    Http::fake(['*/v1/documents/extract-text' => Http::response(['error' => 'something_new', 'message' => 'unknown'], 422)]);

    expect(fn () => ProcessScannedDocument::dispatchSync($document->id))
        ->toThrow(TextExtractionUnavailableException::class);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::PROCESSING);
});

it('does not request external extraction, and creates no extraction row, when the subject has not consented', function (): void {
    $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    Http::fake(['*/v1/documents/extract-text' => Http::response(extractionSuccessBody([['page_number' => 1, 'text' => 'x']]))]);

    // A fake provider IS bound, but consent was never granted — it must never be called.
    $this->app->instance(ExternalDocumentAiProvider::class, new class () implements ExternalDocumentAiProvider {
        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            throw new RuntimeException('must not be called without consent');
        }
    });

    ProcessScannedDocument::dispatchSync($document->id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::READY);
    expect(DocumentExtraction::query()->count())->toBe(0);
});

it('proposes an extraction, writes one audit event, and never auto-confirms it, once consent and a provider both exist', function (): void {
    Event::fake([DocumentExtractionProposed::class]);

    $document = Document::factory()->create(['mime_type' => 'application/pdf', 'status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    Http::fake(['*/v1/documents/extract-text' => Http::response(
        extractionSuccessBody([['page_number' => 1, 'text' => 'CNP 1234567890123 IBAN RO00BANK']]),
    )]);

    $this->app->instance(ExternalDocumentAiProvider::class, new class () implements ExternalDocumentAiProvider {
        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            return new DocumentAiResponse('acme-ai', 'acme-extractor-v3', '1.0.0', ['vendor' => 'ACME SRL'], '0.9100');
        }
    });

    ProcessScannedDocument::dispatchSync($document->id);

    $extraction = DocumentExtraction::query()->where('document_id', $document->id)->sole();
    expect($extraction->status)->toBe(ExtractionStatus::PROPOSED)
        ->and($extraction->extractor_type)->toBe(ExtractorType::EXTERNAL_DOCUMENT_AI)
        ->and($extraction->model_provider)->toBe('acme-ai')
        ->and($extraction->model_name)->toBe('acme-extractor-v3');

    // Nothing auto-confirms it (BLUEPRINT.md §14.6) — mass assignment cannot move it out of PROPOSED.
    $extraction->fill(['status' => ExtractionStatus::ACCEPTED])->save();
    $freshExtraction = $extraction->fresh();
    expect($freshExtraction)->not->toBeNull();
    assert($freshExtraction !== null);
    expect($freshExtraction->status)->toBe(ExtractionStatus::PROPOSED);

    expect(AuditEvent::query()->where('event_type', 'ExternalDocumentAiSendCompleted')->count())->toBe(1);

    Event::assertDispatched(DocumentExtractionProposed::class, function (DocumentExtractionProposed $event) use ($extraction): bool {
        $payload = $event->broadcastWith();

        return $payload['extraction_id'] === $extraction->id && $payload['extraction_status'] === 'PROPOSED';
    });
});
