<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Exceptions;

use RuntimeException;

/**
 * The reader refused *this file*, for a reason a person can act on.
 *
 * The reason is a closed code — `NO_TEXT_LAYER`, `ENCRYPTED_FILE`, `TOO_LARGE`, `NO_TABLE`,
 * `UNSUPPORTED_FORMAT`, `TOO_MANY_ROWS`, `TOO_MANY_PAGES` — which the screen turns into a sentence
 * in the person's language. The message never carries a line of the file.
 */
final class StatementRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function withReason(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
