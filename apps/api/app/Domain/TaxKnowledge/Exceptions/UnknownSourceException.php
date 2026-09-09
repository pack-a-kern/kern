<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller names a source code that has no entry in `config/sources.php`. Never
 * thrown for "the source is temporarily unreachable" — that is a fetch-time failure, not a
 * registry failure.
 */
final class UnknownSourceException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('No source registry entry for code "%s" — check config/sources.php.', $code));
    }
}
