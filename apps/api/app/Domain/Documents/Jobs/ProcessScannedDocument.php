<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Enums\AiExtractionOutcome;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\Events\DocumentAiExtractionFinished;
use App\Domain\Documents\Events\DocumentExtractionProposed;
use App\Domain\Documents\Events\DocumentTextExtracted;
use App\Domain\Documents\Events\DocumentTextExtractionRejected;
use App\Domain\Documents\ExternalAi\ExplainsAiExtractionOutcome;
use App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException;
use App\Domain\Documents\Extraction\PdfPageCounter;
use App\Domain\Documents\Extraction\TextExtractionGateway;
use App\Domain\Documents\FileSecurity\SniffedFileType;
use App\Domain\Documents\Models\Document;
use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\Tenancy\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * `BLUEPRINT.md` §14.1: native text extraction, the OCR-needed decision, then the (consent-gated,
 * currently-unimplemented) structured-extraction seam — for a document already promoted out of
 * quarantine by `ScanQuarantinedPart`.
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
 *     exactly as `ScanQuarantinedPart` leaves an unscanned document at `QUARANTINE`.
 *   - `DocumentRejectedByExtractionException` (the service rejected *this* document — an
 *     encrypted PDF, too many pages, ...) — never retried; the document moves straight to
 *     `DocumentStatus::REJECTED` with the closed `RejectionReason`, the same fail-fast treatment
 *     `ScanQuarantinedPart` gives an infected upload.
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

    /**
     * One attempt at the AI step, not three.
     *
     * Everything before it — the scan, the text extraction — is idempotent and cheap to repeat. The
     * extraction is neither: a retry sends somebody's document to a provider a second time, and a
     * failure here is almost always a configuration or a model problem that a retry will reproduce
     * exactly.
     */
    public int $tries = 1;


    /** How many pages a document may have before it is refused unread. */
    private int $maxPages;

    public function __construct(public readonly string $documentId)
    {
        $this->maxPages = app(AiProviderCatalogue::class)->maxDocumentPages();
        // Read through the catalogue rather than `config()` directly, so the job and the provider
        // that makes the call cannot disagree about what the configured timeout is.
        $this->timeout = app(AiProviderCatalogue::class)->extractionTimeoutSeconds()
            + app(AiProviderCatalogue::class)->jobTimeoutMarginSeconds();

        /*
         * Its own queue, worked by exactly one process.
         *
         * The expensive part of this job is a single HTTP call to one model endpoint, and ten uploads
         * used to mean ten of those at once — the default queue is worked by three processes locally and
         * ten in production. A self-hosted model serves them by taking each one longer, so the batch
         * finishes no sooner while every individual document creeps toward the timeout, and a document
         * that would have taken 30 seconds alone can miss its deadline for no reason but company.
         *
         * Serial also makes the queue legible: one document is being read at a time, and the rest are
         * waiting their turn, which is what the list can honestly say it is doing.
         *
         * Scanning stays parallel — `ScanQuarantinedPart` talks to ClamAV, which is local and
         * concurrent, and holding those up would delay every document's promotion for nothing.
             */
        $this->onQueue(config()->string('ai.providers.queue'));
    }

    public function handle(
        EncryptedObjectStore $realStore,
        PdfPageCounter $pageCounter,
        RequestExternalDocumentExtraction $requestExternalExtraction,
        AuditChainService $audit,
    ): void {
        $document = Document::withoutGlobalScope(TenantScope::class)->with('parts')->find($this->documentId);

        // Deleted while this waited in the queue: there is nothing left to read, and failing would
        // only leave a failed job behind for a document the person already threw away.
        if ($document === null) {
            return;
        }

        $document->status = DocumentStatus::PROCESSING;
        // The previous attempt's verdict goes with it. Left standing, the review screen reads it and
        // shows the old failure within a second of a retry starting — while the new request is still
        // running, for minutes — so a person watches an error that is no longer true.
        $document->ai_extraction_outcome = null;
        $document->ai_extraction_attempted_at = null;
        $document->save();

        // The one thing read locally, and only for PDFs: how long the document is. Walking a page
        // tree costs milliseconds where a provider reading 260 pages costs minutes, so a document
        // too long to be worth reading is refused here — before anything, local or remote, has
        // looked at its contents.
        $pages = 0;

        foreach ($document->parts as $part) {
            if ($part->status !== DocumentStatus::SCANNED || $part->mime_type !== SniffedFileType::PDF->value) {
                continue;
            }

            $stream = $realStore->get($part->objectKey());

            try {
                $pages += $pageCounter->countPages($stream);
            } catch (DocumentRejectedByExtractionException $exception) {
                $this->rejectDocument($document, $exception, $audit);

                return;
            } finally {
                fclose($stream);
            }
        }

        if ($pages > $this->maxPages) {
            $this->rejectDocument(
                $document,
                DocumentRejectedByExtractionException::forReason(
                    RejectionReason::TOO_MANY_PAGES,
                    'The document has more pages than this installation accepts.',
                ),
                $audit,
            );

            return;
        }

        $document->status = DocumentStatus::READY;
        $document->save();

        // What arrived, and how long it was. Never the content: the document is encrypted at rest
        // and an audit payload is not the place to undo that.
        $audit->record(AuditEventInput::occurringNow(
            subjectId: $document->tax_subject_id,
            actorId: null,
            eventType: 'DocumentTextExtracted',
            resourceType: 'Document',
            resourceId: $document->id,
            payloadHash: $audit->hashPayload([
                'part_count' => $document->parts->count(),
                'page_count' => $pages,
            ]),
        ));

        DocumentTextExtracted::dispatch($document->id, $document->tax_subject_id, DocumentStatus::READY, false);

        // A statement uploaded to be imported is evidence, not something to read with a model: its
        // rows are parsed locally and turned into transactions by a person going through them. Asking
        // a model to rewrite the same file would cost a send of every name and figure in it, for an
        // answer nothing uses.
        if ($document->source === DocumentSource::IMPORT) {
            return;
        }

        try {
            $extraction = $requestExternalExtraction($document);
        } catch (QueryException $exception) {
            // A reading takes minutes, and a document deleted during it makes saving the reading
            // fail on its foreign key. That one case ends quietly, like the check at the top; any
            // other database failure is a real fault and still fails the job.
            if (Document::withoutGlobalScope(TenantScope::class)->whereKey($document->id)->exists()) {
                throw $exception;
            }

            return;
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


}
