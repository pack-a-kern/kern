<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Exceptions;

use RuntimeException;

/**
 * The mapping does not read the file.
 *
 * A mapping that parses four rows in five is a guess that fits the top of the file: the rest would
 * arrive as transactions with no date or no amount, or — worse — with an amount read under the wrong
 * decimal separator. Both counts travel with the exception so the screen can say "reads 812 of 940
 * rows" rather than "invalid".
 */
final class MappingNotUsableException extends RuntimeException
{
    public function __construct(
        public readonly int $parsed,
        public readonly int $total,
    ) {
        parent::__construct("The mapping reads only {$parsed} of {$total} rows.");
    }
}
