<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use DateTimeImmutable;
use RuntimeException;

/**
 * `App\Domain\TaxEngine\Services\FxConversionPolicy::convert()` with
 * `App\Domain\TaxEngine\Enums\FxConversionMethod::PREVIOUS_BANKING_DAY` walks backward from the
 * requested date looking for a published rate, bounded by
 * `FxConversionPolicy::MAX_PREVIOUS_BANKING_DAY_LOOKBACK_DAYS` — a purely technical search bound
 * (long bank holiday runs), never a fiscal fact. Exhausting it without finding a rate is a real
 * data gap, never silently treated as "no conversion needed".
 */
final class FxRateUnavailableWithinLookbackException extends RuntimeException
{
    public static function forSearch(string $currencyCode, DateTimeImmutable $requestedDate, int $lookbackDays): self
    {
        return new self(sprintf(
            'No %s rate found within %d day(s) before %s.',
            $currencyCode,
            $lookbackDays,
            $requestedDate->format('Y-m-d'),
        ));
    }
}
