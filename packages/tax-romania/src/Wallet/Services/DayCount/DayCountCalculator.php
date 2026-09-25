<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\DayCount;

use DateTimeImmutable;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Exceptions\InvalidDateRangeException;
use Kern\TaxRomania\Wallet\ValueObjects\Decimal;

/**
 * Pure day-count-convention math: no HTTP, no Eloquent, no `now()`, no env, no randomness, no
 * cache — every input is a parameter, matching the same purity discipline `docs/AGENTS.md` §6
 * requires of `Domain/TaxEngine`'s tax rules, applied here because a day-count error is exactly
 * the kind of silent off-by-a-few-days defect the task brief warns about.
 *
 * `$from` is inclusive, `$to` is exclusive — the standard accrual-period convention ("interest
 * accrues for each day in `[from, to)`"), so a one-day period is `$from` and `$to = $from + 1 day`.
 *
 * ## ACTUAL_360 / ACTUAL_365
 *
 * Actual calendar days between the two dates (`$to->diff($from)->days`, always non-negative
 * because `$to > $from` is enforced below), divided by the fixed denominator. `ACTUAL_365` never
 * adjusts for leap years — 365 is a fixed denominator under this convention by definition, not
 * "365 or 366 depending on the year".
 *
 * Worked example: `2026-01-01` to `2026-04-01` (a 90-day first quarter: 31 + 28 + 31) under
 * `ACTUAL_360` is `90 / 360 = 0.25`; under `ACTUAL_365` it is `90 / 365 ≈ 0.2465753425`.
 *
 * ## THIRTY_360
 *
 * The classic "30/360" (also called "Bond Basis" / "30/360 US") method: every month is treated as
 * having exactly 30 days. Given `$from = (Y1, M1, D1)` and `$to = (Y2, M2, D2)`:
 *
 * ```text
 * if D1 == 31: D1 = 30
 * if D2 == 31 and D1 == 30 (after the adjustment above): D2 = 30
 * days = (Y2 - Y1) * 360 + (M2 - M1) * 30 + (D2 - D1)
 * fraction = days / 360
 * ```
 *
 * Worked examples (hand-computed, both asserted in
 * `tests/Unit/Domain/Wallet/DayCountCalculatorTest.php`):
 *  - `2026-01-01` to `2026-02-01`: D1=1, D2=1, no adjustment; `days = 0*360 + 1*30 + 0 = 30`;
 *    `fraction = 30/360 = 1/12`.
 *  - `2026-01-31` to `2026-03-31`: D1=31 → adjusted to 30; D2=31 and D1(adjusted)=30 → D2 adjusted
 *    to 30; `days = 0*360 + 2*30 + 0 = 60`; `fraction = 60/360 = 1/6` — this is the case the
 *    adjustment rule exists for: naive subtraction (`31 - 31 = 0` days for the day component)
 *    would silently understate the period by a full day pair without it.
 */
final class DayCountCalculator
{
    private const int SCALE = 15;

    public function yearFraction(DayCountConvention $convention, DateTimeImmutable $from, DateTimeImmutable $to): Decimal
    {
        if ($to <= $from) {
            throw InvalidDateRangeException::endNotAfterStart($from->format('Y-m-d'), $to->format('Y-m-d'));
        }

        return match ($convention) {
            DayCountConvention::ACTUAL_360 => $this->actualOverConstant($from, $to, 360),
            DayCountConvention::ACTUAL_365 => $this->actualOverConstant($from, $to, 365),
            DayCountConvention::THIRTY_360 => $this->thirty360($from, $to),
        };
    }

    private function actualOverConstant(DateTimeImmutable $from, DateTimeImmutable $to, int $denominator): Decimal
    {
        // DateInterval::$days is only ever `false` for an interval built from individual date
        // parts (e.g. `new DateInterval('P1D')`) rather than from `diff()` — a `diff()` result
        // always carries a real day count, which is the only way this property is populated here.
        $days = $to->diff($from)->days;

        return Decimal::fromInt($days)->divide(Decimal::fromInt($denominator), self::SCALE);
    }

    private function thirty360(DateTimeImmutable $from, DateTimeImmutable $to): Decimal
    {
        $y1 = (int) $from->format('Y');
        $m1 = (int) $from->format('n');
        $d1 = (int) $from->format('j');

        $y2 = (int) $to->format('Y');
        $m2 = (int) $to->format('n');
        $d2 = (int) $to->format('j');

        if ($d1 === 31) {
            $d1 = 30;
        }

        if ($d2 === 31 && $d1 === 30) {
            $d2 = 30;
        }

        $days = ($y2 - $y1) * 360 + ($m2 - $m1) * 30 + ($d2 - $d1);

        return Decimal::fromInt($days)->divide(Decimal::fromInt(360), self::SCALE);
    }
}
