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

/** An amount in its currency, in the reader's conventions — "4.050 lei", "RON 4,050". */
export function formatAmount(amount: number, currency: string, locale: string): string {
    return new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: 2 }).format(amount);
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
export function formatPercent(fraction: number, locale: string): string {
    return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 2 }).format(fraction);
}
