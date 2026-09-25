<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

/**
 * `Kern\TaxRomania\TaxEngine\Services\FxConversionPolicy::convertToRon()` with
 * `Kern\TaxRomania\TaxEngine\Enums\FxConversionMethod::MONTHLY_AVERAGE` found zero published daily
 * rates anywhere in the requested calendar month — a genuine data gap, never treated as "average
 * of nothing is zero".
 */
final class NoDailyRatesForPeriodException extends RuntimeException
{
    public static function forMonth(string $currencyCode, int $year, int $month): self
    {
        return new self(sprintf('No published %s rates found for %04d-%02d.', $currencyCode, $year, $month));
    }
}
