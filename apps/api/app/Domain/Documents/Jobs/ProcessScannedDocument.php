<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\Enums\AiExtractionOutcome;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Events\DocumentAiExtractionFinished;
use App\Domain\Documents\Events\DocumentExtractionProposed;
use App\Domain\Documents\Events\DocumentTextExtracted;
use App\Domain\Documents\Events\DocumentTextExtractionRejected;
use App\Domain\Documents\ExternalAi\ExplainsAiExtractionOutcome;
use App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException;
use App\Domain\Documents\Extraction\TextExtractionGateway;
use App\Domain\Documents\FileSecurity\SniffedFileType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentDerivative;
use App\Domain\Documents\Parsing\ProposeParsedExtraction;
use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * `BLUEPRINT.md` §14.1: native text extraction, the OCR-needed decision, then the (consent-gated,
 * currently-unimplemented) structured-extraction seam — for a document already promoted out of
 * quarantine by `ScanQuarantinedDocument`.
 *
 * A PDF's native text (if any) is extracted and stored as a `document_derivatives` row
 * (`DocumentObjectKind::EXTRACTED_TEXT`) before `documents.status` reaches `READY`. An image has
 * no native text layer by definition — `$needsOcr` is trivially `true`, no derivative is created,
 * and no Python call happens for it.
 *
 * `documents.status` reaches `READY` regardless of whether the following external-extraction
 * attempt succeeds: `App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction` failing
 * with "no consent" or "no provider configured" (`docs/STATUS.md` blocked item #3) is an accepted,
 * visible product limitation (ADR 0005), not a reason to withhold a document a human can already
 * review from `READY`.
 *
 * `TextExtractionGateway::extractNativeText()` can fail two distinguishable ways
 * (`docs/handoff/024-extraction-endpoint.md`), and this job treats them differently:
 *
 *   - `TextExtractionUnavailableException` (the service itself is unreachable/misconfigured) —
 *     this job throws and is retried; the document is left at `PROCESSING`, never advanced,
 *     exactly as `ScanQuarantinedDocument` leaves an unscanned document at `QUARANTINE`.
 *   - `DocumentRejectedByExtractionException` (the service rejected *this* document — an
 *     encrypted PDF, too many pages, ...) — never retried; the document moves straight to
 *     `DocumentStatus::REJECTED` with the closed `RejectionReason`, the same fail-fast treatment
 *     `ScanQuarantinedDocument` gives an infected upload.
 *
 * Extracted text is carried as `UntrustedDocumentText` (`BLUEPRINT.md` §14.5) until it must cross an
 * explicit boundary — written as raw bytes to the encrypted derivative, or handed to the
 * consent-gated external-AI request (`DocumentAiRequest` treats it as data, never instructions) —
 * never unwrapped into a bare `string` any earlier than that.
 */
final class ProcessScannedDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    /**
     * How long this job may run, derived from the extraction it waits on rather than fixed.
     *
     * The default job timeout is 60 seconds and a vision model reading a photograph takes longer
     * than that, so the worker would kill the job mid-request — leaving the document `PROCESSING`
     * forever with nothing recorded about why.
     *
     * Derived, not a constant, because the two must stay in order: the HTTP call has to be the
     * thing that gives up first, so it fails with a reason the pipeline can record. A hardcoded
     * ceiling here would silently become the *shorter* of the two the moment someone raised
     * `AI_EXTRACTION_TIMEOUT_SECONDS`, and every long extraction would die as an unexplained job
     * timeout instead.
     *
     * The margin covers everything around the call — reading the document out of the object store,
     * decrypting it, base64, and writing the proposal.
     */
    public int $timeout;

    /** Enough for the object-store read, the decrypt, base64 and the write around the HTTP call. */
    private const int TIMEOUT_MARGIN_SECONDS = 60;

    /**
     * One attempt at the AI step, not three.
     *
     * Everything before it — the scan, the text extraction — is idempotent and cheap to repeat. The
     * extraction is neither: a retry sends somebody's document to a provider a second time, and a
     * failure here is almost always a configuration or a model problem that a retry will reproduce
     * exactly.
     */
    public int $tries = 1;

    public function __construct(public readonly string $documentId)
    {
        // Read through the catalogue rather than `config()` directly, so the job and the provider
        // that makes the call cannot disagree about what the configured timeout is.
        $this->timeout = app(AiProviderCatalogue::class)->extractionTimeoutSeconds()
            + self::TIMEOUT_MARGIN_SECONDS;
    }

    public function handle(
        EncryptedObjectStore $realStore,
        TextExtractionGateway $textExtraction,
        RequestExternalDocumentExtraction $requestExternalExtraction,
        AuditChainService $audit,
        ProposeParsedExtraction $proposeParsed,
    ): void {
        $document = Document::withoutGlobalScope(TaxSubjectScope::class)->findOrFail($this->documentId);
        $document->status = DocumentStatus::PROCESSING;
        $document->save();

        $objectKey = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::ORIGINAL);

        $needsOcr = true;
        $extractedText = null;

        if ($document->mime_type === SniffedFileType::PDF->value) {
            $stream = $realStore->get($objectKey);

            try {
                $result = $textExtraction->extractNativeText($stream);
            } catch (DocumentRejectedByExtractionException $exception) {
                $this->rejectDocument($document, $exception, $audit);

                return;
            } finally {
                fclose($stream);
            }

            $needsOcr = $result->needsOcr;
            $extractedText = $result->joinedText();

            $this->storeExtractedTextDerivative($document, $realStore, $extractedText->reveal());
        }

        $document->status = DocumentStatus::READY;
        $document->save();

        // What was actually got out of the document. `needs_ocr` is the honest part: true means no
        // usable text layer was found, so everything downstream is working from nothing until an
        // OCR path exists. `characters` is a count, never the text — the extracted content is
        // encrypted at rest and an audit payload is not the place to leak it.
        $audit->record(AuditEventInput::occurringNow(
            subjectId: $document->tax_subject_id,
            actorId: null,
            eventType: 'DocumentTextExtracted',
            resourceType: 'Document',
            resourceId: $document->id,
            payloadHash: $audit->hashPayload([
                'mime_type' => $document->mime_type,
                'needs_ocr' => $needsOcr,
                'characters' => $extractedText === null ? 0 : strlen($extractedText->reveal()),
            ]),
        ));

        DocumentTextExtracted::dispatch($document->id, $document->tax_subject_id, DocumentStatus::READY, $needsOcr);

        // Deterministic parsers first, and usually last. A parser that knows this document's layout
        // is reproducible from the same bytes forever, costs nothing, needs no consent and no
        // network call, and for the layouts it recognises beats a model on accuracy. Only a document
        // no parser recognises is worth sending anywhere.
        if ($extractedText !== null) {
            $parsed = $proposeParsed($document, $extractedText->reveal());

            if ($parsed !== null) {
                DocumentExtractionProposed::dispatch(
                    $document->id,
                    $document->tax_subject_id,
                    $parsed->id,
                    DocumentStatus::READY,
                    $parsed->status,
                );

                return;
            }
        }

        // Reached for anything a parser could not read — which, until there is OCR, is every
        // photograph. This used to be unreachable for them: the call sat behind the same
        // `$extractedText !== null` guard, so an image was stored, scanned, and then simply stopped.
        try {
            $extraction = $requestExternalExtraction($document, $extractedText);
        } catch (ExplainsAiExtractionOutcome $exception) {
            // Every way this can end carries its own outcome, so there is one catch rather than a
            // `match` on exception class that would drift the first time a case was added.
            //
            // All of them are recorded, including the ordinary non-faults — no consent, no provider,
            // nothing sendable. Those used to return in silence, which left the review screen saying
            // "no proposed extraction yet" for a document that was never going to get one, and the
            // person in front of it with no way to find out why.
            $this->recordAiOutcome($document, $exception->outcome());

            // Only genuine faults reach the log. A subject who declined consent is not an incident.
            if ($exception->outcome()->isFailure()) {
                report($exception);
            }

            DocumentAiExtractionFinished::dispatch(
                $document->id,
                $document->tax_subject_id,
                DocumentStatus::READY,
                $exception->outcome(),
            );

            return;
        }

        $this->recordAiOutcome($document, AiExtractionOutcome::SUCCEEDED);

        DocumentExtractionProposed::dispatch(
            $document->id,
            $document->tax_subject_id,
            $extraction->id,
            DocumentStatus::READY,
            $extraction->status,
        );
    }

    /**
     * Stamp the document with what came of its one external-AI attempt.
     *
     * Written even for success, so the column always describes the *latest* attempt: recording only
     * failures would leave a stale failure sitting on a document that a retry had since read fine.
     *
     * Deliberately a plain save, not an audit event. The audit chain already records the send itself
     * (`ExternalDocumentAiSendCompleted`, written by `RequestExternalDocumentExtraction` only for a
     * send that genuinely happened); this column is the current state a screen reads, which is a
     * different job from an append-only history and should not pretend to be one.
     */
    private function recordAiOutcome(Document $document, AiExtractionOutcome $outcome): void
    {
        $document->ai_extraction_outcome = $outcome;
        $document->ai_extraction_attempted_at = now();
        $document->save();
    }

    private function rejectDocument(
        Document $document,
        DocumentRejectedByExtractionException $exception,
        AuditChainService $audit,
    ): void {
        $document->status = DocumentStatus::REJECTED;
        $document->rejection_reason = $exception->reason();
        $document->save();

        // A rejection is a decision about someone's document, and the reason is a closed enum, not
        // free text — exactly the kind of thing that has to be defensible later.
        $audit->record(AuditEventInput::occurringNow(
            subjectId: $document->tax_subject_id,
            actorId: null,
            eventType: 'DocumentRejected',
            resourceType: 'Document',
            resourceId: $document->id,
            payloadHash: $audit->hashPayload([
                'reason' => $exception->reason()->value,
                'stage' => 'text_extraction',
            ]),
        ));

        DocumentTextExtractionRejected::dispatch($document->id, $document->tax_subject_id, $exception->reason());
    }

    private function storeExtractedTextDerivative(Document $document, EncryptedObjectStore $realStore, string $extractedText): void
    {
        $derivativeKey = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::EXTRACTED_TEXT);

        $textStream = fopen('php://temp', 'w+b');

        if ($textStream === false) {
            throw new RuntimeException('Unable to open a temporary stream for the extracted-text derivative.');
        }

        try {
            fwrite($textStream, $extractedText);
            rewind($textStream);
            $manifest = $realStore->put($derivativeKey, $textStream);
        } finally {
            fclose($textStream);
        }

        DocumentDerivative::create([
            'document_id' => $document->id,
            'kind' => DocumentObjectKind::EXTRACTED_TEXT,
            'object_path' => $derivativeKey->value,
            'sha256' => $manifest->sha256,
            'encryption_version' => 1,
        ]);
    }
}
