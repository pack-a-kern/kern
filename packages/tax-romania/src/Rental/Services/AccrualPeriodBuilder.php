<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Services;

use DateInterval;
use DateTimeImmutable;
use Kern\TaxRomania\Rental\Enums\RentalPaymentFrequency;
use Kern\TaxRomania\Rental\Exceptions\InvalidDateRangeException;

/**
 * Splits one `rental_contract_versions` row's own life, `[validFrom, validToExclusive)`, into
 * `payment_frequency`-sized period boundaries — the same scheduling idiom
 * `Kern\TaxRomania\Wallet\Services\Interest\DepositPaymentScheduleBuilder` uses for a deposit's
 * payment/capitalisation periods, reimplemented independently here rather than imported
 * (`docs/AGENTS.md` §6 forbids a module importing another module's classes outside the DTO/
 * contract/event shapes `deptrac` enforces, and this task's path scope is `packages/tax-romania/src/Rental/**`
 * only). Pure: no HTTP, no Eloquent, no `now()`.
 *
 * The final period is clipped to `validToExclusive` when the version's life is not an exact
 * multiple of the frequency (e.g. a version amended after 40 days at `MONTHLY` frequency produces
 * one ~30-day period and one short 10-day final period, never a period extending past the
 * amendment/contract-end boundary) — this is exactly what lets a mid-period amendment produce
 * correct accruals on both sides of the change: each version is scheduled independently from its
 * own `valid_from`, so a rent change never straddles a single accrual row.
 */
final class AccrualPeriodBuilder
{
    /**
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> half-open `[from, to)` pairs,
     *                                                                  in order, covering exactly
     *                                                                  `[validFrom, validToExclusive)`
     */
    public function build(RentalPaymentFrequency $frequency, DateTimeImmutable $validFrom, DateTimeImmutable $validToExclusive): array
    {
        if ($validToExclusive <= $validFrom) {
            throw InvalidDateRangeException::endNotAfterStart($validFrom->format('Y-m-d'), $validToExclusive->format('Y-m-d'));
        }

        $step = match ($frequency) {
            RentalPaymentFrequency::MONTHLY => new DateInterval('P1M'),
            RentalPaymentFrequency::QUARTERLY => new DateInterval('P3M'),
            RentalPaymentFrequency::SEMI_ANNUAL => new DateInterval('P6M'),
            RentalPaymentFrequency::ANNUAL => new DateInterval('P1Y'),
        };

        $periods = [];
        $cursor = $validFrom;

        while ($cursor < $validToExclusive) {
            $next = $cursor->add($step);

            if ($next > $validToExclusive) {
                $next = $validToExclusive;
            }

            $periods[] = [$cursor, $next];
            $cursor = $next;
        }

        return $periods;
    }
}
