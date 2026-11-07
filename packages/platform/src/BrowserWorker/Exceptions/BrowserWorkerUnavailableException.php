<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The browser worker could not be asked: not configured, not reached, or answering in a shape this
 * client does not know.
 *
 * Distinct from every other exception here, each of which is the worker answering — full, refusing a
 * run, refusing a page. A caller that tells the person "try again shortly" for a full worker and "the
 * browser is not set up" for this one needs them apart.
 */
final class BrowserWorkerUnavailableException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The browser worker is not configured.');
    }

    public static function requestFailed(Throwable $previous): self
    {
        return new self('The browser worker could not be reached.', 0, $previous);
    }

    public static function unexpectedResponse(int $status): self
    {
        return new self("The browser worker returned an unexpected HTTP status {$status}.", $status);
    }

    public static function malformedResponse(): self
    {
        return new self('The browser worker returned a response that did not match the expected contract.');
    }
}
