<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi\DataTransferObjects;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Settings\DataTransferObjects\AiProviderCredentials;

/**
 * What would be sent to an external document-AI provider — ADR 0005's consent-gated path.
 * `extractedText` carries the untrusted document content `UntrustedDocumentText` marks structurally
 * (`docs/handoff/019-pdf-text-extraction.md`, `BLUEPRINT.md` §14.5) — never a bare `string`, so a
 * future real `ExternalDocumentAiProvider` implementation cannot accidentally format it into a
 * system/instruction message; it must call `->reveal()` explicitly and pass the result only as a
 * clearly-delimited data field.
 *
 * `credentials` is resolved fresh, per call, from `App\Domain\Settings\Services\
 * ResolveAiProviderCredentials` (`docs/handoff/041-settings-module.md`) — never cached across
 * requests — so a real future `ExternalDocumentAiProvider` implementation already receives which
 * provider/base URL/model/API key an administrator has configured *right now*, with no code
 * change needed on this DTO's side when that implementation is built.
 */
final readonly class DocumentAiRequest
{
    public function __construct(
        public string $documentId,
        public string $subjectId,
        public string $mimeType,
        public ?UntrustedDocumentText $extractedText,
        public AiProviderCredentials $credentials,
        /**
         * The document's own bytes, for a type nothing could read locally.
         *
         * Mutually exclusive with `$extractedText`: a PDF sends the text PyMuPDF already pulled
         * out, a photograph sends the image. Sending both would ask the model to reconcile two
         * versions of one document.
         *
         * Null for a document with usable text — which keeps the smaller, cheaper and more private
         * path the default, exactly as ADR 0005 intends.
         */
        public ?DocumentContent $content = null,
        /** Which document type's schema the reply must satisfy (`BLUEPRINT.md` §14.4). */
        public ?DocumentType $documentType = null,
    ) {
    }
}
