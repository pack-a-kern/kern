<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Exceptions;

use RuntimeException;

final class NoDailyRatesForPeriodException extends RuntimeException
{
    public static function forMonth(string $currencyCode, int $year, int $month): self
    {
        return new self(sprintf('No daily rates for %s in %04d-%02d.', $currencyCode, $year, $month));
    }
}
