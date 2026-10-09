<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

/**
 * A year still running cannot be final.
 *
 * Not an arithmetic rule — the engine would compute 2026 happily today. It is a rule about the calendar: income
 * can still arrive, so a figure called final in October would be wrong in November, and the lock it puts on the
 * year's transactions would stop somebody recording the income that proves it wrong.
 */
final class FiscalYearNotClosedException extends RuntimeException
{
    public static function for(int $fiscalYear): self
    {
        return new self(
            "The {$fiscalYear} fiscal year has not ended. A year can be marked final from 1 January of the "
            . 'year after it.',
        );
    }
}
