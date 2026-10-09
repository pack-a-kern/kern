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
 * A bare figure in the reader's conventions, for a place too narrow to repeat its currency — "285.229,85".
 *
 * The caller must state the currency beside it. Meant for a chart's hole and nothing else: an amount without
 * its denomination is a bug waiting to happen on any page that shows more than one currency.
 */
export function formatFigure(amount: number, locale: string): string {
    return new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
}

/** A rate given as a fraction — `0.1` — as a percent, "10%". */
export function formatPercent(fraction: number, locale: string): string {
    return new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 2 }).format(fraction);
}
