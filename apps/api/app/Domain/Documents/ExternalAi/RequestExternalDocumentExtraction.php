<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\Consent\Exceptions\AiConsentRequiredException;
use App\Domain\Documents\Enums\ExtractorType;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Settings\Services\ResolveAiProviderCredentials;

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
    ) {
    }

    /**
     * @throws AiConsentRequiredException
     * @throws ExternalDocumentAiProviderNotConfiguredException
     */
    public function __invoke(Document $document, ?UntrustedDocumentText $extractedText): DocumentExtraction
    {
        $this->consentGate->assertConsented($document->tax_subject_id);

        $credentials = ($this->resolveCredentials)();

        if (! $credentials->enabled) {
            throw ExternalDocumentAiProviderNotConfiguredException::disabledBySettings();
        }

        $response = $this->provider->sendForExtraction(new DocumentAiRequest(
            documentId: $document->id,
            subjectId: $document->tax_subject_id,
            mimeType: $document->mime_type,
            extractedText: $extractedText,
            credentials: $credentials,
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
}
