/**
 * Turning a deposit's term into its dates.
 *
 * A term deposit is bought as a *length* — "6 luni" — and the maturity date follows from it. The
 * form therefore asks for the length and derives the date here, rather than asking someone to type
 * a date: gross interest is `principal x rate x days / denominator`, and those days are counted
 * from exactly these two dates, so a maturity that is one day out changes a figure that reaches a
 * tax return while nothing on the screen looks wrong.
 *
 * Kept free of `Date`'s local timezone: every value is an ISO `YYYY-MM-DD` calendar date, parsed
 * and built as UTC. Constructing `new Date('2026-03-01')` and reading `getMonth()` west of
 * Greenwich yields February, which would shift a maturity by a day for some users and not others.
 */

/** A calendar date with no time and no zone — the only shape these functions accept or return. */
const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * `date` plus `months`, clamped to the end of the target month.
 *
 * The clamp is what a bank does and what JavaScript does not: 31 January plus one month is
 * 28 February (29 in a leap year), where `Date.setMonth` rolls forward into 3 March. A deposit
 * opened on the 31st would otherwise mature two or three days late, and be paid interest for days
 * it did not run.
 *
 * Returns an empty string for anything that is not an ISO date, so a half-typed input renders as
 * blank rather than as `Invalid Date`.
 */
export function addMonths(date: string, months: number): string {
    const match = ISO_DATE.exec(date);

    if (match === null || !Number.isInteger(months)) {
        return '';
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);

    // Zero-based month arithmetic, carried into the year by hand rather than by `setMonth`, so the
    // day-of-month clamp below happens before any date object exists to roll over.
    const zeroBased = month - 1 + months;
    const targetYear = year + Math.floor(zeroBased / 12);
    const targetMonth = ((zeroBased % 12) + 12) % 12;

    // Day 0 of the *next* month is the last day of this one.
    const lastDay = new Date(Date.UTC(targetYear, targetMonth + 1, 0)).getUTCDate();

    return format(new Date(Date.UTC(targetYear, targetMonth, Math.min(day, lastDay))));
}

/**
 * Whole days from `from` to `to`, or `null` if either is not an ISO date.
 *
 * Both endpoints are UTC midnight, so this is an exact difference in days with no DST hour to round
 * away — the count the interest is calculated over.
 */
export function daysBetween(from: string, to: string): number | null {
    const start = parse(from);
    const end = parse(to);

    if (start === null || end === null) {
        return null;
    }

    return Math.round((end - start) / 86_400_000);
}

function parse(date: string): number | null {
    const match = ISO_DATE.exec(date);

    if (match === null) {
        return null;
    }

    return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}

function format(date: Date): string {
    return date.toISOString().slice(0, 10);
}
