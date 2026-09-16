<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Exceptions;

use RuntimeException;

/**
 * Something tried to change an import that is no longer being decided — one already imported, undone
 * or failed.
 *
 * Refused rather than allowed quietly: the rows of a committed import are the provenance of real
 * transactions, and editing them afterwards would make that record describe something that never
 * happened.
 */
final class ImportNotOpenException extends RuntimeException
{
    public static function forImport(string $importId): self
    {
        return new self("Statement import {$importId} is no longer open for changes.");
    }
}
