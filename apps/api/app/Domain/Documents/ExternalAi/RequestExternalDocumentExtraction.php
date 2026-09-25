<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\Consent\Exceptions\AiConsentRequiredException;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\ExtractorType;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentContent;
use App\Domain\Documents\ExternalAi\Exceptions\DocumentNotSendableForExtractionException;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderFailedException;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\FileSecurity\SniffedFileType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\ObjectStore\EncryptedObjectStore;
use Kern\Platform\Settings\DataTransferObjects\AiProviderCredentials;
use Kern\Platform\Settings\Services\AiProviderCatalogue;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;

/**
 * ADR 0005's consent-gated external send, end to end: the gate, the actual call, and the audit
 * trail — in that order, so an audit event is written **only for a send that genuinely happened**.
 *
 * Ordering is deliberate and load-bearing:
 *
 *   1. {@see DocumentAiConsentGate::assertConsented()} — throws {@see AiConsentRequiredException}
 *      before the provider is ever touched if consent is missing or revoked. No audit event, no
 *      call.
 *   2. {@see ResolveAiProviderCredentials} — resolved **fresh on every call**
 *      (`docs/handoff/041-settings-module.md`), never cached across requests, so an administrator
 *      flipping `ai.enabled` off/on or changing the provider through `PUT /api/v1/settings/ai`
 *      takes effect starting with the very next document, no restart required. If the system-wide
 *      switch is off, throws {@see ExternalDocumentAiProviderNotConfiguredException} — still no
 *      audit event, because nothing was sent.
 *   3. {@see ExternalDocumentAiProvider::sendForExtraction()} — the actual send, given the
 *      resolved credentials via {@see DocumentAiRequest::$credentials}. In production this always
 *      throws {@see ExternalDocumentAiProviderNotConfiguredException} (`NullDocumentAiProvider`,
 *      `docs/STATUS.md` blocked item #3 — *which* provider/region/DPA, a business decision this
 *      task does not make) — still no audit event, because nothing was sent.
 *   4. Only once the provider returns successfully: one audit event
 *      (`ExternalDocumentAiSendCompleted`, naming the document, the provider, and the subject) is
 *      recorded, per `docs/AGENTS.md` rule 13, and a `document_extractions` row is proposed.
 *
 * Callers (`App\Domain\Documents\Jobs\ProcessScannedDocument`) are expected to catch both
 * `AiConsentRequiredException` and `ExternalDocumentAiProviderNotConfiguredException` and simply
 * leave the document at `DocumentStatus::READY` with no extraction row — this capability being
 * unavailable is an accepted, visible product limitation (ADR 0005 Consequences), not an error to
 * retry.
 */
final readonly class RequestExternalDocumentExtraction
{
    private const string EXTRACTOR_VERSION = '2026.09.0';

    /** Enough for a long statement's rows, far short of anything that would bloat a row. */
    private const int MAX_KEPT_REPLY_BYTES = 200_000;

    public function __construct(
        private DocumentAiConsentGate $consentGate,
        private ExternalDocumentAiProvider $provider,
        private AuditChainService $audit,
        private ResolveAiProviderCredentials $resolveCredentials,
        private EncryptedObjectStore $objectStore,
        private AiProviderCatalogue $catalogue,
    ) {
    }

    /**
     * @throws AiConsentRequiredException
     * @throws ExternalDocumentAiProviderNotConfiguredException
     * @throws DocumentNotSendableForExtractionException
     * @throws Exceptions\ExternalDocumentAiProviderFailedException
     */
    public function __invoke(Document $document): DocumentExtraction
    {
        $this->consentGate->assertConsented($document->tax_subject_id);

        $credentials = ($this->resolveCredentials)();

        if (! $credentials->enabled) {
            throw ExternalDocumentAiProviderNotConfiguredException::disabledBySettings();
        }

        // The pictures the document is made of. Every image part goes, in order: an invoice
        // photographed across four pages is one document, and a model shown one page of it answers
        // about one page of it. This is the send ADR 0005 gates — the consent check above has
        // already passed, or nothing reached here.
        //
        // A PDF part contributes its text instead, extracted locally: smaller, cheaper, and
        // revealing less than the page image would. So a document is sent as its text plus its
        // photographs, and never as two versions of the same file.
        $contents = $this->readImageParts($document);
        $attachments = $this->readFileParts($document);
        $text = $this->readTextParts($document);

        // Nothing readable at all. Sending anyway would ask a model to read a document it was never
        // given, and the only reply it could produce is an invented one — the single failure mode a
        // system built on provenance must not have. Refused here, recorded by the caller.
        if ($text === null && $contents === [] && $attachments === []) {
            throw DocumentNotSendableForExtractionException::nothingToSend();
        }

        try {
            $response = $this->provider->sendForExtraction(new DocumentAiRequest(
                documentId: $document->id,
                subjectId: $document->tax_subject_id,
                mimeType: $document->firstPart()?->mime_type,
                extractedText: $text,
                credentials: $credentials,
                contents: $contents,
                attachments: $attachments,
            ));
        } catch (ExternalDocumentAiProviderFailedException $exception) {
            // Kept before rethrowing, so "the reading was missing values this type requires" can be
            // followed by what the model actually said. Under the subject's own key, like every
            // other reading of their document.
            $this->rememberAttempt($document, $credentials, $exception->rawReply);

            throw $exception;
        }

        // Null on success, deliberately. The accepted reading is already stored — validated, and
        // under the same key — in `document_extractions.result_ciphertext`, and keeping the raw
        // reply beside it would be a second encrypted copy of the same financial detail, for a
        // screen that only ever shows it when something went wrong.
        $this->rememberAttempt($document, $credentials, null);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $document->tax_subject_id,
            actorId: null,
            eventType: 'ExternalDocumentAiSendCompleted',
            resourceType: 'Document',
            resourceId: $document->id,
            payloadHash: $this->audit->hashPayload([
                'document_id' => $document->id,
                'model_provider' => $response->modelProvider,
                'model_name' => $response->modelName,
            ]),
        ));

        // What the model decided this is. A document uploaded untyped has no other source for it,
        // and saving it here keeps the two reading paths symmetric — the parser does the same the
        // moment it recognises a layout.
        if ($document->type !== $response->documentType) {
            $document->type = $response->documentType;
            $document->save();
        }

        return DocumentExtraction::propose(
            document: $document,
            extractorType: ExtractorType::EXTERNAL_DOCUMENT_AI,
            extractorVersion: self::EXTRACTOR_VERSION,
            modelProvider: $response->modelProvider,
            modelName: $response->modelName,
            schemaVersion: $response->schemaVersion,
            result: $response->result,
            confidenceDecimal: $response->confidenceDecimal,
            durationMs: $response->durationMs,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
        );
    }

    /**
     * What was asked and what came back, replacing whatever the last attempt left.
     *
     * The model is recorded here rather than only on a successful extraction row, because the
     * question "it failed — on which model?" is the one an administrator asks after changing models,
     * and a failure creates no extraction row to hold the answer.
     *
     * The reply is kept only when the attempt failed, because that is the only case nothing else
     * records: a successful reading is already stored, validated, in `document_extractions`. It is
     * also bounded — a model that ignores its instructions can emit a great deal, and an unbounded
     * column is how one bad answer becomes a database problem.
     */
    private function rememberAttempt(Document $document, AiProviderCredentials $credentials, ?string $reply): void
    {
        $document->ai_model_provider = $credentials->provider;
        $document->ai_model_name = $credentials->model;
        $document->ai_reply_ciphertext = $reply === null || trim($reply) === ''
            ? null
            : mb_strimwidth($reply, 0, self::MAX_KEPT_REPLY_BYTES, '…');
        $document->save();
    }

    /**
     * The document's file parts, for the provider to read itself.
     *
     * PDFs only. A spreadsheet is already text and there is nothing for a document-understanding
     * pass to add; an image is looked at, not read. Bounded by the same ceiling as an image,
     * because base64 inflates both the same way.
     *
     * @return list<array{filename: string, mime_type: string, base64: string}>
     */
    private function readFileParts(Document $document): array
    {
        $maxBytes = $this->catalogue->extractionMaxImageBytes();
        $files = [];

        foreach ($document->parts as $part) {
            if ($part->status !== DocumentStatus::SCANNED || $part->size_bytes > $maxBytes) {
                continue;
            }

            if (SniffedFileType::tryFrom($part->mime_type) !== SniffedFileType::PDF) {
                continue;
            }

            $stream = $this->objectStore->get($part->objectKey());

            try {
                $bytes = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }

            if ($bytes !== false) {
                $files[] = [
                    // A name the provider can show in an error, never the person's own filename —
                    // that is encrypted at rest and has no business leaving the building.
                    'filename' => 'document-' . (string) $part->position . '.pdf',
                    'mime_type' => $part->mime_type,
                    'base64' => base64_encode($bytes),
                ];
            }
        }

        return $files;
    }

    /**
     * The document's text parts, which is to say its spreadsheets.
     *
     * A CSV *is* text: there is nothing to extract and no reason to make a provider re-parse what
     * arrived readable. Joined in part order, like everything else about a document.
     */
    private function readTextParts(Document $document): ?UntrustedDocumentText
    {
        $texts = [];

        foreach ($document->parts as $part) {
            if ($part->status !== DocumentStatus::SCANNED) {
                continue;
            }

            if (SniffedFileType::tryFrom($part->mime_type) !== SniffedFileType::CSV) {
                continue;
            }

            $stream = $this->objectStore->get($part->objectKey());

            try {
                $bytes = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }

            if ($bytes !== false && trim($bytes) !== '') {
                $texts[] = UntrustedDocumentText::wrap($bytes);
            }
        }

        return $texts === [] ? null : UntrustedDocumentText::join($texts);
    }

    /**
     * The document's picture parts, as bytes the model can look at.
     *
     * Refuses anything over the configured ceiling rather than truncating: half a receipt is not a
     * smaller receipt, and base64 inflates a payload by a third on top of whatever the image
     * already was. A part too large to send is skipped and the rest still go — a four-page invoice
     * with one oversized page is more readable than nothing at all — and a document where *every*
     * part is refused and there is no text is reported as not sendable by the caller.
     *
     * Only image types. A PDF part has already given its text, and a format nothing can read
     * locally *and* no model can look at has nothing to gain from being uploaded somewhere.
     *
     * @return list<DocumentContent>
     */
    private function readImageParts(Document $document): array
    {
        $maxBytes = $this->catalogue->extractionMaxImageBytes();
        $contents = [];

        foreach ($document->parts as $part) {
            if ($part->status !== DocumentStatus::SCANNED) {
                continue;
            }

            // Asked of the type, not of its MIME string: a `str_starts_with('image/')` here used
            // to admit `image/webp`, which nothing else in this pipeline accepts.
            $type = SniffedFileType::tryFrom($part->mime_type);

            if ($type === null || ! $type->isImage() || $part->size_bytes > $maxBytes) {
                continue;
            }

            $stream = $this->objectStore->get($part->objectKey());

            try {
                $bytes = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }

            if ($bytes !== false) {
                $contents[] = DocumentContent::fromBytes($bytes, $part->mime_type);
            }
        }

        return $contents;
    }
}
