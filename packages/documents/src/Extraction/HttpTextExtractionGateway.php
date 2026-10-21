<?php

declare(strict_types=1);

namespace Kern\Documents\Extraction;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Kern\Documents\Enums\RejectionReason;
use Kern\Documents\Extraction\DataTransferObjects\NativeTextExtractionResult;
use Kern\Documents\Extraction\DataTransferObjects\PageExtractionResult;
use Kern\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use Kern\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException;
use Kern\Documents\Extraction\Exceptions\TextExtractionUnavailableException;
use Kern\Platform\Http\StackService;
use Throwable;

/**
 * Calls the Python `python-api` service's native-PDF-text-extraction endpoint over the internal
 * `processing_net` network (`BLUEPRINT.md` §3.3) with the (already AV-scanned, already
 * `EncryptedObjectStore`-decrypted) PDF bytes.
 *
 * This is the real, verified contract (`docs/handoff/024-extraction-endpoint.md`), reconciled
 * against `docs/handoff/022-document-pipeline.md`'s original, unverified guess — see that
 * handoff's "Differences from handoff 022's assumed contract" for the full diff this class closes:
 *
 *   `POST {base_url}/v1/documents/extract-text`
 *   `Authorization: Bearer <services.processing.auth_token>` — required; the same shared
 *   secret as the Python service's `INTERNAL_SERVICE_AUTH_TOKEN` (`compose.yaml`, `.env.example`).
 *   Multipart body, field `file` = the raw PDF bytes.
 *
 *   `200`:
 *   `{"page_count": int, "needs_ocr": bool, "overall_decision": "...", "pages_needing_ocr": [...],
 *     "pages": [{"page_number": int, "quality_score": "<decimal string>", "quality_signals": {...},
 *                "needs_ocr": bool,
 *                "text": {"kind": "untrusted_document_text", "content": string}}, ...]}`.
 *   Only `page_count`, top-level `needs_ocr`, and each page's `page_number`/`quality_score`/`text`
 *   are consumed — see `NativeTextExtractionResult`/`PageExtractionResult`'s own docblocks for why
 *   `overall_decision`/`pages_needing_ocr`/per-page `needs_ocr`/`quality_signals` are not (yet)
 *   mapped into this module's DTOs.
 *
 *   `401 {"error": "unauthorized", ...}` — the shared secret is missing/wrong/unconfigured on
 *   either side. Treated as {@see TextExtractionUnavailableException} (an operational failure to
 *   alert on and retry), never as a reason to reject the user's document.
 *
 *   `413 {"error": "file_too_large", ...}` and `422 {"error": "invalid_magic_bytes" |
 *   "encrypted_pdf" | "too_many_pages" | "empty_document" | "malformed_pdf" |
 *   "extraction_timeout" | "extraction_failed", ...}` — the service looked at *this* document and
 *   rejected it. Mapped to {@see DocumentRejectedByExtractionException} with the matching closed
 *   {@see RejectionReason}, never collapsed into "service unavailable" (handoff 024's "Differences"
 *   #4 — the two must read differently to a user: "this document is bad, fix it and re-upload"
 *   versus "our service failed, we will retry").
 *
 *   Any other non-2xx status, a connection failure, or a response that fails to parse remain
 *   {@see TextExtractionUnavailableException} — this client has no way to distinguish "the service
 *   failed" from itself, by construction.
 */
final readonly class HttpTextExtractionGateway implements PdfPageCounter, TextExtractionGateway
{
    /** @var array<string, RejectionReason> Python `error` code => Laravel's closed reason. */
    private const array REJECTION_REASONS_BY_ERROR_CODE = [
        'file_too_large' => RejectionReason::FILE_TOO_LARGE,
        'invalid_magic_bytes' => RejectionReason::INVALID_MAGIC_BYTES,
        'encrypted_pdf' => RejectionReason::ENCRYPTED_PDF,
        'too_many_pages' => RejectionReason::TOO_MANY_PAGES,
        'empty_document' => RejectionReason::EMPTY_DOCUMENT,
        'malformed_pdf' => RejectionReason::MALFORMED_PDF,
        'extraction_timeout' => RejectionReason::EXTRACTION_TIMEOUT,
        'extraction_failed' => RejectionReason::EXTRACTION_FAILED,
    ];

    public function __construct(
        private HttpFactory $http,
        private StackService $service,
        private int $timeoutSeconds,
    ) {
    }

    public function countPages($pdfStream): int
    {
        $body = $this->call($pdfStream, '/v1/documents/page-count');
        $count = is_array($body) ? ($body['page_count'] ?? null) : null;

        if (! is_int($count) || $count < 1) {
            throw TextExtractionUnavailableException::malformedResponse();
        }

        return $count;
    }

    public function extractNativeText($pdfStream): NativeTextExtractionResult
    {
        return $this->parse($this->call($pdfStream, '/v1/documents/extract-text'));
    }

    /**
     * One call to the service, with the rejection handling both endpoints share.
     *
     * Extracted because the two differ only in path and in what they make of the body: everything
     * about *failing* — an unconfigured token, a rejected one, a documented "this document is bad"
     * code, an unreachable service — is the same question with the same answer.
     *
     * @param  resource  $pdfStream
     */
    private function call($pdfStream, string $path): mixed
    {
        if (! $this->service->hasToken()) {
            throw TextExtractionUnavailableException::authenticationNotConfigured();
        }

        $bytes = stream_get_contents($pdfStream);

        if ($bytes === false) {
            throw TextExtractionUnavailableException::malformedResponse();
        }

        try {
            $response = $this->service->request($this->http, $this->timeoutSeconds)
                ->attach('file', $bytes, 'document.pdf')
                ->post($this->service->url($path));
        } catch (ConnectionException $exception) {
            throw TextExtractionUnavailableException::requestFailed($exception);
        } catch (Throwable $exception) {
            throw TextExtractionUnavailableException::requestFailed($exception);
        }

        if ($response->status() === 401) {
            throw TextExtractionUnavailableException::authenticationRejected();
        }

        if ($response->failed()) {
            $this->throwForFailedResponse($response);
        }

        return $response->json();
    }

    /**
     * @throws DocumentRejectedByExtractionException for a documented "this document is bad" code
     * @throws TextExtractionUnavailableException     for anything else
     */
    private function throwForFailedResponse(Response $response): never
    {
        // `json_decode` on the raw body, not `Response::json()`: the latter's PHPDoc-inferred
        // `array` return type would make PHPStan treat the `is_array()` guard below as
        // statically redundant, but it is not — a non-2xx body is not always even valid JSON
        // (the `''`/`503` case this class also handles). Normalising to an array once, here,
        // keeps that a genuine runtime guard rather than a repeated one.
        $decoded = json_decode($response->body(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $errorCode = is_string($payload['error'] ?? null) ? $payload['error'] : null;
        $reason = $errorCode !== null ? self::REJECTION_REASONS_BY_ERROR_CODE[$errorCode] ?? null : null;

        if ($reason === null) {
            throw TextExtractionUnavailableException::unexpectedResponse($response->status());
        }

        $message = is_string($payload['message'] ?? null)
            ? $payload['message']
            : "The document text-extraction service rejected the document ({$errorCode}).";

        throw DocumentRejectedByExtractionException::forReason($reason, $message);
    }

    private function parse(mixed $payload): NativeTextExtractionResult
    {
        if (! is_array($payload)
            || ! isset($payload['page_count'], $payload['needs_ocr'], $payload['pages'])
            || ! is_int($payload['page_count'])
            || ! is_bool($payload['needs_ocr'])
            || ! is_array($payload['pages'])
        ) {
            throw TextExtractionUnavailableException::malformedResponse();
        }

        $pages = [];

        foreach ($payload['pages'] as $page) {
            $parsedPage = $this->parsePage($page);
            $pages[$parsedPage->pageNumber] = $parsedPage;
        }

        return new NativeTextExtractionResult(
            pageCount: $payload['page_count'],
            needsOcr: $payload['needs_ocr'],
            pages: $pages,
        );
    }

    private function parsePage(mixed $page): PageExtractionResult
    {
        if (! is_array($page)
            || ! isset($page['page_number'], $page['quality_score'], $page['text'])
            || ! is_int($page['page_number'])
            || ! is_string($page['quality_score'])
            || ! is_array($page['text'])
            || ! isset($page['text']['kind'], $page['text']['content'])
            || $page['text']['kind'] !== 'untrusted_document_text'
            || ! is_string($page['text']['content'])
        ) {
            throw TextExtractionUnavailableException::malformedResponse();
        }

        return new PageExtractionResult(
            pageNumber: $page['page_number'],
            qualityScoreDecimal: $page['quality_score'],
            text: UntrustedDocumentText::wrap($page['text']['content']),
        );
    }
}
