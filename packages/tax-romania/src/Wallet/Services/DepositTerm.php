<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Carbon\CarbonImmutable;

/**
 * A deposit's term and its dates — the one place they are worked out.
 *
 * A term deposit is bought as a length, "6 luni", and its maturity follows from it: the start plus the
 * months, clamped to the end of a shorter month the way a bank counts (31 January plus one month is the
 * last day of February, never 3 March), then moved off a weekend to the Monday after — a bank pays out on
 * a day it is open, and interest runs to that day. Public holidays are not moved: nothing here lists them.
 *
 * Gross interest is `principal × rate × days ÷ denominator`, and those days are counted between exactly
 * these two dates, so a maturity a day out is a figure on a tax return nothing on the screen shows wrong.
 */
final class DepositTerm
{
    /** The start plus the term, clamped to the end of the month, before any weekend is moved. */
    public static function end(CarbonImmutable $startsAt, int $months): CarbonImmutable
    {
        return $startsAt->startOfDay()->addMonthsNoOverflow($months);
    }

    /** When a deposit started on `$startsAt` for `$months` matures: the term's end, off a weekend. */
    public static function maturity(CarbonImmutable $startsAt, int $months): CarbonImmutable
    {
        $end = self::end($startsAt, $months);

        return match ($end->dayOfWeekIso) {
            6 => $end->addDays(2),
            7 => $end->addDay(),
            default => $end,
        };
    }

    /**
     * The term, in months, that produced a stored maturity — the inverse of {@see maturity()}.
     *
     * Not a plain month difference: a term ending on Saturday 31 October matures on Monday 2 November,
     * which the calendar reads as a month longer than was bought. The neighbours of the calendar count are
     * tried, and the one whose maturity matches wins; a maturity no term produces is the calendar count.
     */
    public static function months(CarbonImmutable $startsAt, CarbonImmutable $maturityAt): int
    {
        $calendar = ($maturityAt->year - $startsAt->year) * 12 + ($maturityAt->month - $startsAt->month);

        foreach ([$calendar, $calendar - 1, $calendar + 1] as $months) {
            if ($months >= 1 && self::maturity($startsAt, $months)->isSameDay($maturityAt)) {
                return $months;
            }
        }

        return max(1, $calendar);
    }

    /** The days interest runs over: from the start to the maturity, whole. */
    public static function days(CarbonImmutable $startsAt, CarbonImmutable $maturityAt): int
    {
        return (int) $startsAt->startOfDay()->diffInDays($maturityAt->startOfDay());
    }
}
