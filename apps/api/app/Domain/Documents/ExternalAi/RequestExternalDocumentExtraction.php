<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\Consent\Exceptions\AiConsentRequiredException;
use App\Domain\Documents\Enums\ExtractorType;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentContent;
use App\Domain\Documents\ExternalAi\Exceptions\DocumentNotSendableForExtractionException;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Domain\Settings\Services\ResolveAiProviderCredentials;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\ObjectKey;

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
    public function __invoke(Document $document, ?UntrustedDocumentText $extractedText): DocumentExtraction
    {
        $this->consentGate->assertConsented($document->tax_subject_id);

        $credentials = ($this->resolveCredentials)();

        if (! $credentials->enabled) {
            throw ExternalDocumentAiProviderNotConfiguredException::disabledBySettings();
        }

        // A document with no text has to be *looked at*, so the bytes go instead. This is the send
        // ADR 0005 gates: the consent check above has already passed, or nothing reached here.
        //
        // Text wins where there is any. It is smaller, cheaper, and reveals less — a PDF's text
        // layer carries the words without the letterhead, the signature or whatever else is in the
        // image — so the picture is a fallback, never the default.
        $content = $extractedText === null ? $this->readContent($document) : null;

        // Neither words nor a picture. Sending anyway would ask a model to read a document it was
        // never given, and the only reply it could produce is an invented one — the single failure
        // mode a system built on provenance must not have. Refused here, recorded by the caller.
        if ($extractedText === null && $content === null) {
            throw DocumentNotSendableForExtractionException::nothingToSend();
        }

        $response = $this->provider->sendForExtraction(new DocumentAiRequest(
            documentId: $document->id,
            subjectId: $document->tax_subject_id,
            mimeType: $document->mime_type,
            extractedText: $extractedText,
            credentials: $credentials,
            content: $content,
            // The schema the reply must satisfy is the document's own type's.
            documentType: $document->type,
        ));

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

        return DocumentExtraction::propose(
            document: $document,
            extractorType: ExtractorType::EXTERNAL_DOCUMENT_AI,
            extractorVersion: self::EXTRACTOR_VERSION,
            modelProvider: $response->modelProvider,
            modelName: $response->modelName,
            schemaVersion: $response->schemaVersion,
            result: $response->result,
            confidenceDecimal: $response->confidenceDecimal,
        );
    }

    /**
     * The document's own bytes, or null when they should not be sent.
     *
     * Refuses anything over the configured ceiling rather than truncating: half a receipt is not a
     * smaller receipt, and base64 inflates a payload by a third on top of whatever the image
     * already was. A document too large to send is reported as a failed extraction, and the person
     * types it in — which is what they would have done anyway.
     *
     * Only image types. A format nothing can read locally *and* no model can look at has nothing to
     * gain from being uploaded somewhere.
     */
    private function readContent(Document $document): ?DocumentContent
    {
        if (! str_starts_with($document->mime_type, 'image/')) {
            return null;
        }

        $maxBytes = $this->catalogue->extractionMaxImageBytes();

        if ($document->size_bytes > $maxBytes) {
            return null;
        }

        $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::ORIGINAL);
        $stream = $this->objectStore->get($key);

        try {
            $bytes = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        return $bytes === false ? null : DocumentContent::fromBytes($bytes, $document->mime_type);
    }
}
