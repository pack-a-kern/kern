<?php

declare(strict_types=1);

namespace App\Domain\Rental\Services;

use DateTimeImmutable;

/**
 * The due date for one accrual period, given the contract version's `payment_day` (day-of-month
 * rent is contractually due, `1..31`, or `null` = "due at the start of the period"). Pure: no
 * HTTP, no Eloquent, no `now()`.
 *
 * `payment_day` is interpreted against `$periodStart`'s own month, then clamped into
 * `[$periodStart, $periodEnd)`:
 *  - a `payment_day` past the end of that month (e.g. `31` in February) is clamped to the last
 *    calendar day of the month, per `DateTimeImmutable::modify('last day of this month')`;
 *  - a `payment_day` that falls before `$periodStart` (e.g. a mid-month amendment starting on the
 *    15th with `payment_day = 5`) is clamped to `$periodStart` itself — the rent for that partial
 *    period cannot sensibly be due before the period begins;
 *  - a `payment_day` at or past `$periodEnd` is clamped to the last day of the period
 *    (`$periodEnd`'s previous day) for the identical reason in the other direction.
 */
final class RentalDueDateCalculator
{
    public function forPeriod(DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, ?int $paymentDay): DateTimeImmutable
    {
        if ($paymentDay === null) {
            return $periodStart;
        }

        $lastDayOfMonth = (int) $periodStart->modify('last day of this month')->format('d');
        $clampedDay = min($paymentDay, $lastDayOfMonth);
        $candidate = $periodStart->setDate((int) $periodStart->format('Y'), (int) $periodStart->format('n'), $clampedDay);

        if (($candidate <=> $periodStart) < 0) {
            return $periodStart;
        }

        if (($candidate <=> $periodEnd) >= 0) {
            return $periodEnd->modify('-1 day');
        }

        return $candidate;
    }
}
