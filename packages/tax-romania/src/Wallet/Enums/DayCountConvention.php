<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Enums;

/**
 * `deposits.day_count_convention` (`BLUEPRINT.md` §6.4 names the column, requires it be explicit per
 * deposit, but does not name literal values — this is standard financial-market vocabulary, not a
 * fiscal/legal citation, so no `source_section_id` applies here (`docs/AGENTS.md` §9 governs
 * fiscal values; a day-count convention is a contractual term of the deposit, chosen by the bank
 * and recorded from the deposit agreement, not a tax rule this codebase could get "wrong" against
 * an official source).
 *
 * Four conventions, each implemented in
 * `Kern\TaxRomania\Wallet\Services\DayCount\DayCountCalculator`:
 *  - `ACTUAL_360` — actual calendar days between two dates, divided by 360.
 *  - `ACTUAL_365` — actual calendar days, divided by 365 (no leap-year adjustment: 365 is a fixed
 *    denominator under this convention, not "365 or 366 depending on the year").
 *  - `THIRTY_360` — the classic "30/360" (also called "Bond Basis" / "30/360 US") method: every
 *    month is treated as having exactly 30 days. See that calculator's own docblock for the exact
 *    date-adjustment rule and worked examples.
 *  - `ACTUAL_ACTUAL` — actual days over the actual days of the year that begins on the first day: 365,
 *    or 366 when that year holds a 29 February. Tezaur's own formula (ADR 0025 §9).
 */
enum DayCountConvention: string
{
    case ACTUAL_360 = 'ACTUAL_360';
    case ACTUAL_365 = 'ACTUAL_365';
    case THIRTY_360 = 'THIRTY_360';
    case ACTUAL_ACTUAL = 'ACTUAL_ACTUAL';
}
