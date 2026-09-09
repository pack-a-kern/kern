<?php

declare(strict_types=1);

namespace App\Domain\Documents\Extraction\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The text-extraction service itself could not be reached, is misconfigured, timed out, or
 * returned a response this client does not understand — as opposed to
 * {@see DocumentRejectedByExtractionException}, which means the service looked at this specific
 * document and rejected it for a documented reason (`docs/handoff/024-extraction-endpoint.md`'s
 * "Differences from handoff 022's assumed contract" #4: these two must never be collapsed into
 * one undifferentiated failure, because they mean different things to the user — "our service
 * failed, we will retry" versus "this document is bad, fix it and re-upload"). Callers must not
 * guess an OCR decision when this is thrown — the job should fail and retry, the same fail-closed
 * posture `Scanning\VirusScanner` takes.
 */
final class TextExtractionUnavailableException extends RuntimeException
{
    public static function requestFailed(?Throwable $previous = null): self
    {
        return new self('The document text-extraction service could not be reached.', 0, $previous);
    }

    public static function unexpectedResponse(int $status): self
    {
        return new self("The document text-extraction service returned an unexpected HTTP status {$status}.");
    }

    public static function malformedResponse(): self
    {
        return new self('The document text-extraction service returned a response that did not match the expected contract.');
    }

    /**
     * `documents.text_extraction.auth_token` is empty — Laravel's own fail-closed check, mirroring
     * the Python endpoint's own "setting unset -> 401" behaviour (`docs/handoff/024`, decision
     * #1). Never send a request with no bearer token at all; fail before the call is made.
     */
    public static function authenticationNotConfigured(): self
    {
        return new self('The document text-extraction service auth token is not configured.');
    }

    /**
     * The service returned `401` — the shared secret this Laravel deployment sends does not match
     * `INTERNAL_SERVICE_AUTH_TOKEN` on the Python side (or that setting is unset there). Never
     * includes the token value.
     */
    public static function authenticationRejected(): self
    {
        return new self('The document text-extraction service rejected this request as unauthenticated.');
    }
}
