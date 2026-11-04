/**
 * Locale-aware formatting shared across screens.
 *
 * Every function here takes an explicit `locale` rather than defaulting to one. This app ships in
 * Romanian and English, and a default would quietly format an English page with Romanian
 * conventions — the same bug that had three screens calling `toLocaleString('ro-RO')` and one
 * writing the literal string "KB" beside a divided number.
 */

/** A date and time, medium date plus short time. Falls back to the raw value on a bad input. */
export function formatDateTime(iso: string, locale: string): string {
    try {
        return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

const MINUTE = 60;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/** Past this, "27 days ago" is arithmetic dressed as a sentence, and a date is the shorter answer. */
const RELATIVE_HORIZON = 7 * DAY;

/**
 * When something happened, as a reader asks it: "2 hours ago", not a timestamp to subtract from now.
 *
 * For the screens whose question is recency — what the queue just did, when an account was last
 * seen, what the audit chain recorded a moment ago. A calendar date that is data rather than an
 * event (a contract's start, a law's validity) stays {@link formatDate}: "3 months ago" is the wrong
 * thing to say about a rule that took effect in July.
 *
 * Beyond a week it returns the date instead. Relative phrasing stops earning its place about there —
 * nobody counts in 23-day units — and the exact instant is a hover away wherever this is shown.
 *
 * `numeric: 'auto'` so the languages that have a word for it use it: `ieri`, not `acum 1 zi`.
 *
 * It reads the clock, so it is only correct at the moment it runs. Every screen using it recomputes
 * on reload and on any filter change, which is where these lists are read; a page left open
 * overnight will say "5 hours ago" until something touches it.
 */
export function formatWhen(iso: string, locale: string): string {
    try {
        const moment = new Date(iso);
        const seconds = (moment.getTime() - Date.now()) / 1000;
        const distance = Math.abs(seconds);

        if (distance >= RELATIVE_HORIZON) {
            return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(moment);
        }

        const relative = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

        if (distance < MINUTE) {
            return relative.format(Math.round(seconds), 'second');
        }

        if (distance < HOUR) {
            return relative.format(Math.round(seconds / MINUTE), 'minute');
        }

        if (distance < DAY) {
            return relative.format(Math.round(seconds / HOUR), 'hour');
        }

        return relative.format(Math.round(seconds / DAY), 'day');
    } catch {
        /* An unparseable value gives `NaN`, which every comparison above answers `false` to and
           `RelativeTimeFormat` then throws on — so a bad timestamp arrives here rather than rendering
           as "NaN days ago". */
        return iso;
    }
}

/**
 * A calendar date (`YYYY-MM-DD`), with no time and no timezone.
 *
 * Read and formatted in UTC: a date-only value parsed in the viewer's zone lands on the previous day
 * anywhere west of Greenwich, and a contract's start date is a day, not an instant.
 */
export function formatDate(isoDate: string, locale: string): string {
    try {
        return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(
            new Date(`${isoDate.slice(0, 10)}T00:00:00Z`),
        );
    } catch {
        return isoDate;
    }
}

/**
 * A byte count as a human size.
 *
 * `Intl.NumberFormat`'s unit style rather than a hand-rolled `/1024` and a literal "KB": both the
 * unit's name and the decimal separator differ by language. Steps up to megabytes at 1024 KB so a
 * 4 MB scan does not read as "4096 KB".
 */
export function formatBytes(bytes: number, locale: string): string {
    const kilobytes = bytes / 1024;
    const useMegabytes = kilobytes >= 1024;

    try {
        return new Intl.NumberFormat(locale, {
            style: 'unit',
            unit: useMegabytes ? 'megabyte' : 'kilobyte',
            maximumFractionDigits: useMegabytes ? 1 : 0,
        }).format(useMegabytes ? kilobytes / 1024 : kilobytes);
    } catch {
        // A runtime without the unit style. The number alone beats throwing inside a table cell.
        return String(bytes);
    }
}

/**
 * An amount in its currency, in the reader's conventions — "4.050,00 lei", "RON 4,050.00".
 *
 * A figure whose currency is not known is still a figure. `Intl.NumberFormat` throws a RangeError on
 * anything that is not a three-letter code, and an empty one reaches here whenever a source has no
 * currency of its own — an imported statement read into an account that never had one, for instance.
 * Throwing takes down the whole screen over a missing label, so the number is shown plainly instead
 * and the currency is simply absent, which is the truth about that row.
 */
export function formatAmount(amount: number, currency: string, locale: string): string {
    return amountFormatter(currency, locale).format(amount);
}

/**
 * The same amount `formatAmount` writes, in its pieces — the whole part, the decimals, the currency —
 * so a figure can set the part that is read large and the rest small (`components/Money`). One
 * formatter for both, so the pieces always join back into exactly the string `formatAmount` gives.
 */
export function amountParts(amount: number, currency: string, locale: string): Intl.NumberFormatPart[] {
    return amountFormatter(currency, locale).formatToParts(amount);
}

function amountFormatter(currency: string, locale: string): Intl.NumberFormat {
    const known = /^[A-Za-z]{3}$/.test(currency);

    return new Intl.NumberFormat(locale, {
        ...(known ? { style: 'currency' as const, currency: currency.toUpperCase() } : {}),
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** An amount held in minor units (bani, cents), as the API sends money; a dash where there is none. */
export function formatMinor(minor: number | null, currency: string, locale: string): string {
    return minor === null ? '—' : formatAmount(minor / 100, currency, locale);
}

/** A count or a measure, in the reader's conventions — "12.480", "12,480". */
export function formatNumber(value: number, locale: string, maximumFractionDigits = 0): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits }).format(value);
}

/**
 * A figure shortened for somewhere a reader glances rather than reads — "933,9 K", "2,7 M".
 *
 * ## Where this is allowed
 *
 * Only where the figure is *context*: a chart's hole, a legend, a tile that explains the number beside it.
 * **Never for an amount somebody acts on.** `113,2 K RON` on a tax bill is a figure nobody can pay, file or
 * check, and rounding what is owed to three significant digits loses hundreds of lei to save eight characters.
 *
 * Callers pair it with the exact value in a `title`, so the full figure is always one hover away.
 *
 * Below 10,000 it returns the ordinary formatting: "9.450" is already short, and "9,5 K" is both longer to read
 * and less precise.
 */
export function formatCompact(amount: number, locale: string): string {
    if (Math.abs(amount) < 10000) {
        return new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(amount);
    }

    return new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(amount);
}

/** A rate given as a fraction — `0.1` — as a percent, "10%". */
export function formatPercent(fraction: number, locale: string, maximumFractionDigits = 2): string {
    return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits }).format(fraction);
}
