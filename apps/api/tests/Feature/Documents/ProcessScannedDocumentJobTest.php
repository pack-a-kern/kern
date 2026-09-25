<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\ProcessScannedDocumentJobTest;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Documents\Enums\AiExtractionOutcome;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Enums\RejectionReason;
use Kern\Documents\Events\DocumentAiExtractionFinished;
use Kern\Documents\Events\DocumentTextExtracted;
use Kern\Documents\Events\DocumentTextExtractionRejected;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use Kern\Documents\ExternalAi\ExternalDocumentAiProvider;
use Kern\Documents\Jobs\ProcessScannedDocument;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\ObjectKey;
use Kern\Platform\KeyManagement\SubjectDekService;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\Fixtures\Documents\FileFixtures;

/**
 * The reading stage, after the scan.
 *
 * Only one thing is read locally now — how many pages a PDF has — and only so an over-long document
 * can be refused before anything expensive looks at it. Everything else is the provider's job, so
 * what is tested here is the gate, the outcomes, and that a refused document never reaches a model.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    Storage::fake('s3');
    Http::preventStrayRequests();

    // `ai.enabled` fails closed by default, and a document nobody may send is refused before any
    // provider is reached — which would make every test here about the wrong thing.
    config(['ai.providers.env_fallback.enabled' => true]);
});

function putOriginal(Document $document, string $content): ObjectKey
{
    $part = $document->parts->first();

    if ($part === null) {
        throw new RuntimeException('The document has no part to store bytes for.');
    }

    $key = $part->objectKey();
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

function fakePageCount(int $pages): void
{
    Http::fake(['*/v1/documents/page-count' => Http::response(['page_count' => $pages])]);
}

function fakeProvider(?DocumentAiResponse $response): void
{
    app()->instance(ExternalDocumentAiProvider::class, new class ($response) implements ExternalDocumentAiProvider {
        public function __construct(private ?DocumentAiResponse $response)
        {
        }

        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            if ($this->response === null) {
                throw new RuntimeException('The provider should not have been called.');
            }

            return $this->response;
        }
    });
}

function proposal(): DocumentAiResponse
{
    return new DocumentAiResponse(
        'acme-ai',
        'acme-extractor-v3',
        '1.0.0',
        ['amount' => '100.00', 'date' => '2026-01-05'],
        '0.9100',
        DocumentType::RECEIPT,
    );
}

it('refuses a document with more pages than this installation accepts, without asking a model', function (): void {
    Event::fake([DocumentTextExtractionRejected::class]);
    config()->set('documents.max_pages', 10);

    $document = Document::factory()->part(['mime_type' => 'application/pdf'])->create(['status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    fakePageCount(260);
    // The provider must never be reached: refusing an over-long document is the point of counting.
    fakeProvider(null);

    ProcessScannedDocument::dispatchSync($document->id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::REJECTED)
        ->and($document->rejection_reason)->toBe(RejectionReason::TOO_MANY_PAGES);

    Event::assertDispatched(DocumentTextExtractionRejected::class);
});

it('reads a document within the limit and records the proposal', function (): void {
    Event::fake([DocumentTextExtracted::class]);
    config()->set('documents.max_pages', 10);

    $document = Document::factory()->part(['mime_type' => 'application/pdf'])->create(['status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    fakePageCount(3);
    app(\Kern\Documents\Consent\DocumentAiConsentGate::class)->grant($document->tax_subject_id);
    fakeProvider(proposal());

    ProcessScannedDocument::dispatchSync($document->id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::READY)
        ->and($document->ai_extraction_outcome)->toBe(AiExtractionOutcome::SUCCEEDED)
        // The model said what this is; nobody chose it at upload.
        ->and($document->type)->toBe(DocumentType::RECEIPT)
        ->and(DocumentExtraction::query()->where('document_id', $document->id)->count())->toBe(1);

    Event::assertDispatched(DocumentTextExtracted::class);
});

it('never counts pages for a document that has none to count', function (): void {
    config()->set('documents.max_pages', 1);

    // An image has no pages, so the PDF gate does not apply to it and must not refuse it.
    $document = Document::factory()->part(['mime_type' => 'image/png'])->create(['status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::pngWithDimensions(10, 10));

    app(\Kern\Documents\Consent\DocumentAiConsentGate::class)->grant($document->tax_subject_id);
    fakeProvider(proposal());

    ProcessScannedDocument::dispatchSync($document->id);

    expect($document->fresh()?->status)->toBe(DocumentStatus::READY);
});

it('clears the previous attempt\'s verdict when a new one starts', function (): void {
    config()->set('documents.max_pages', 10);

    $document = Document::factory()->part(['mime_type' => 'application/pdf'])->create([
        'status' => DocumentStatus::SCANNED,
        'ai_extraction_outcome' => AiExtractionOutcome::FAILED_UNREACHABLE,
    ]);
    putOriginal($document, FileFixtures::minimalPdf());

    fakePageCount(2);
    app(\Kern\Documents\Consent\DocumentAiConsentGate::class)->grant($document->tax_subject_id);
    fakeProvider(proposal());

    ProcessScannedDocument::dispatchSync($document->id);

    // Left standing, the review screen shows the old failure a second after a retry begins — while
    // the new request is still running, for minutes.
    expect($document->fresh()?->ai_extraction_outcome)->toBe(AiExtractionOutcome::SUCCEEDED);
});

it('records why a model could not be asked, rather than leaving the document looking unread', function (): void {
    Event::fake([DocumentAiExtractionFinished::class]);
    config()->set('documents.max_pages', 10);

    $document = Document::factory()->part(['mime_type' => 'application/pdf'])->create(['status' => DocumentStatus::SCANNED]);
    putOriginal($document, FileFixtures::minimalPdf());

    fakePageCount(1);
    app(\Kern\Documents\Consent\DocumentAiConsentGate::class)->grant($document->tax_subject_id);
    // No provider bound at all: the ordinary state of an installation nobody has configured.
    $this->app->instance(ExternalDocumentAiProvider::class, new class () implements ExternalDocumentAiProvider {
        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            throw \Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException::noProviderBound();
        }
    });

    ProcessScannedDocument::dispatchSync($document->id);

    expect($document->fresh()?->ai_extraction_outcome)
        ->toBe(AiExtractionOutcome::NOT_SENT_PROVIDER_UNCONFIGURED);

    Event::assertDispatched(DocumentAiExtractionFinished::class);
});
