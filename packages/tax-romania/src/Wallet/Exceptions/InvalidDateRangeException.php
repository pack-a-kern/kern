<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;

final class InvalidDateRangeException extends InvalidArgumentException
{
    public static function endNotAfterStart(string $start, string $end): self
    {
        return new self("End date {$end} must be strictly after start date {$start}.");
    }
}
