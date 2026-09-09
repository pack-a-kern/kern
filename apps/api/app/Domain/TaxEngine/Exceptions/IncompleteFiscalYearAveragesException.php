<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use RuntimeException;

/**
 * `BLUEPRINT.md` §7.4: an annual average is the arithmetic mean of the year's 12 monthly averages,
 * never of daily rates directly. If fewer than 12 calendar months of that year have at least one
 * published daily rate, the annual average cannot be honestly computed — never approximated from
 * however many months happen to be available.
 */
final class IncompleteFiscalYearAveragesException extends RuntimeException
{
    public static function forYear(string $currencyCode, int $year, int $monthsAvailable): self
    {
        return new self(sprintf(
            'Cannot compute a %d annual average for %s: only %d of 12 months have a published rate.',
            $year,
            $currencyCode,
            $monthsAvailable,
        ));
    }
}
