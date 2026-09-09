<?php

declare(strict_types=1);

namespace App\Domain\Spv\Exceptions;

use RuntimeException;
use Throwable;

/**
 * `apps/spv-browser` could not be reached, is misconfigured, or returned a response this client
 * does not understand — as opposed to {@see SpvPortalAutomationUnavailableException}, which means
 * the worker was reached and correctly answered "the portal action itself is not built yet."
 * Mirrors `App\Domain\Documents\Extraction\Exceptions\TextExtractionUnavailableException`'s
 * identical split.
 */
final class SpvWorkerUnavailableException extends RuntimeException
{
    public static function requestFailed(?Throwable $previous = null): self
    {
        return new self('The SPV browser worker could not be reached.', 0, $previous);
    }

    public static function unexpectedResponse(int $status): self
    {
        return new self("The SPV browser worker returned an unexpected HTTP status {$status}.");
    }

    public static function malformedResponse(): self
    {
        return new self('The SPV browser worker returned a response that did not match the expected contract.');
    }

    public static function authenticationNotConfigured(): self
    {
        return new self('The SPV browser worker auth token is not configured.');
    }

    public static function authenticationRejected(): self
    {
        return new self('The SPV browser worker rejected this request as unauthenticated.');
    }
}
