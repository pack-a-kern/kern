<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The reader itself could not be reached, or answered something this code cannot read.
 *
 * Separate from a refusal of *this file*: that is the person's to act on, while this is the
 * installation's — the import is left where it was and can be tried again once the service is back.
 */
final class StatementParseUnavailableException extends RuntimeException
{
    public static function requestFailed(Throwable $previous): self
    {
        return new self('The statement reader could not be reached.', previous: $previous);
    }

    public static function authenticationNotConfigured(): self
    {
        return new self('The statement reader is not configured with an authentication token.');
    }

    public static function malformedResponse(): self
    {
        return new self('The statement reader answered something unexpected.');
    }
}
