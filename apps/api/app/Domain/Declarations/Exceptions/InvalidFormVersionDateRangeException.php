<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Exceptions;

use InvalidArgumentException;

final class InvalidFormVersionDateRangeException extends InvalidArgumentException
{
    public static function applicableUntilBeforeFrom(string $from, string $until): self
    {
        return new self("applicable_until ({$until}) is not after applicable_from ({$from}).");
    }

    public static function taxYearToBeforeFrom(int $from, int $to): self
    {
        return new self("tax_year_to ({$to}) is before tax_year_from ({$from}).");
    }
}
