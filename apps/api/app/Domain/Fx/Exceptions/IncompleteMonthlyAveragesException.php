<?php

declare(strict_types=1);

namespace App\Domain\Fx\Exceptions;

use RuntimeException;

/**
 * `App\Domain\Fx\Services\FxPeriodAverageCalculator::calculateAnnual()` refuses to average fewer
 * than 12 monthly averages rather than silently averaging over whatever is available — BLUEPRINT.md
 * §7.4's annual-average methodology is defined as the mean of the year's 12 monthly averages, and
 * averaging over an incomplete year would be a silent methodology deviation, not a reasonable
 * default.
 */
final class IncompleteMonthlyAveragesException extends RuntimeException
{
    public static function forYear(string $currencyCode, int $year, int $monthsPresent): self
    {
        return new self(sprintf(
            'Cannot calculate the %d annual average for %s: only %d of 12 monthly averages exist.',
            $year,
            $currencyCode,
            $monthsPresent,
        ));
    }
}
