<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

/**
 * A transaction in a year that has been marked final cannot be changed.
 *
 * Carries the year and the date it was finalised, because "this is locked" without saying which year or since
 * when leaves somebody hunting through nine years of records for the one that refused them.
 */
final class FiscalYearIsFinalException extends RuntimeException
{
    public function __construct(string $message, public readonly int $fiscalYear)
    {
        parent::__construct($message);
    }

    public static function for(int $fiscalYear, ?string $finalisedOn): self
    {
        $since = $finalisedOn === null ? '' : " on {$finalisedOn}";

        return new self(
            "The {$fiscalYear} fiscal year was marked final{$since}. Unlock it before changing its "
            . 'transactions.',
            $fiscalYear,
        );
    }
}
