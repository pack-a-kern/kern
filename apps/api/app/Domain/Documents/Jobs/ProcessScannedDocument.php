<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Consent\Exceptions\AiConsentRequiredException;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Events\DocumentExtractionProposed;
use App\Domain\Documents\Events\DocumentTextExtracted;
use App\Domain\Documents\Events\DocumentTextExtractionRejected;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException;
use App\Domain\Documents\Extraction\TextExtractionGateway;
use App\Domain\Documents\FileSecurity\SniffedFileType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentDerivative;
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

    public function __construct(public readonly string $documentId)
    {
    }

    public function handle(
        EncryptedObjectStore $realStore,
        TextExtractionGateway $textExtraction,
        RequestExternalDocumentExtraction $requestExternalExtraction,
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
                $this->rejectDocument($document, $exception);

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

        DocumentTextExtracted::dispatch($document->id, $document->tax_subject_id, DocumentStatus::READY, $needsOcr);

        try {
            $extraction = $requestExternalExtraction($document, $extractedText);
        } catch (AiConsentRequiredException|ExternalDocumentAiProviderNotConfiguredException) {
            return;
        }

        DocumentExtractionProposed::dispatch(
            $document->id,
            $document->tax_subject_id,
            $extraction->id,
            DocumentStatus::READY,
            $extraction->status,
        );
    }

    private function rejectDocument(Document $document, DocumentRejectedByExtractionException $exception): void
    {
        $document->status = DocumentStatus::REJECTED;
        $document->rejection_reason = $exception->reason();
        $document->save();

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
