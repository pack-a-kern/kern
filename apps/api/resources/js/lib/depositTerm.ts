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

const DAY_MS = 86_400_000;

/** `Date.getUTCDay()` numbering. */
const SUNDAY = 0;
const SATURDAY = 6;

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
 * The first working day on or after `date`: a Saturday or a Sunday becomes the Monday after.
 *
 * A bank pays a deposit out on a day it is open, so a term that ends on a weekend matures on the
 * Monday — and interest runs to that day, which is why the maturity itself moves rather than only
 * the payout. Public holidays are not moved: nothing in this application lists them yet.
 *
 * Returns an empty string for anything that is not an ISO date.
 */
export function nextWorkingDay(date: string): string {
    const time = parse(date);

    if (time === null) {
        return '';
    }

    const weekday = new Date(time).getUTCDay();
    const daysToMonday = weekday === SATURDAY ? 2 : weekday === SUNDAY ? 1 : 0;

    return format(new Date(time + daysToMonday * DAY_MS));
}

/** When a deposit started on `startsAt` for `months` matures: the term's end, moved off a weekend. */
export function maturityFor(startsAt: string, months: number): string {
    return nextWorkingDay(addMonths(startsAt, months));
}

/**
 * The term, in months, that produced a stored maturity — the inverse of {@link maturityFor}.
 *
 * Not a plain month difference: a term ending on Saturday 31 October matures on Monday
 * 2 November, and counting calendar months would read that back as one month longer than it was
 * bought for. The neighbours of the calendar count are tried, and the one whose maturity matches
 * wins; a maturity no term produces falls back to the calendar count.
 */
export function termMonths(startsAt: string, maturityAt: string): number {
    const start = ISO_DATE.exec(startsAt);
    const end = ISO_DATE.exec(maturityAt);

    if (start === null || end === null) {
        return 1;
    }

    const calendar = (Number(end[1]) - Number(start[1])) * 12 + (Number(end[2]) - Number(start[2]));
    const matching = [calendar, calendar - 1, calendar + 1].find(
        (months) => months >= 1 && maturityFor(startsAt, months) === maturityAt,
    );

    return matching ?? Math.max(1, calendar);
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

    return Math.round((end - start) / DAY_MS);
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
