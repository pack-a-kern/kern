<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Exceptions;

use RuntimeException;

/**
 * Thrown when a fetch is attempted against a source whose `config/sources.php` entry has
 * `enabled => false` — a deliberate operational kill switch, distinct from the source simply not
 * existing ({@see UnknownSourceException}).
 */
final class SourceDisabledException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Source "%s" is disabled in config/sources.php.', $code));
    }
}
