import type { Dictionary } from '../i18n/dictionary';
import type { FiscalValueDisplay, ParameterVersion } from '../types/fiscalParameters';
import { formatDate, formatDayMonth, formatMinor, formatPercent } from './format';

type Translate = <T>(pick: (d: Dictionary) => T) => T;

/**
 * A fiscal value in the reader's language, from what the server says it is — an amount, a percent, a day of a
 * month, a yes or a no, so many days, so many minimum wages or a range of them. Only formatted here; which of
 * those a stored value is, the server decides. One place, so the table and the detail agree.
 */
export function describeFiscalValue(display: FiscalValueDisplay, t: Translate, locale: string): string {
    const count = (one: number | null): string => (one === null ? '—' : String(one));

    switch (display.kind) {
        case 'money':
            // The currency is stored with the amount; one missing shows the bare amount rather than a guess.
            return formatMinor(display.amountMinor, display.currency ?? '', locale);
        case 'percent':
            return formatPercent(Number(display.ratio), locale);
        case 'day_month':
            return formatDayMonth(display.day, display.month, locale);
        case 'flag':
            return display.holds ? t((d) => d.pages.taxRules.yes) : t((d) => d.pages.taxRules.no);
        case 'days':
            return t((d) => d.pages.taxRules.days).replace('{count}', String(display.days));
        case 'wages_range':
            return t((d) => d.pages.taxRules.wagesRange)
                .replace('{floor}', count(display.floor))
                .replace('{cap}', count(display.cap));
        case 'wages':
            return t((d) => d.pages.taxRules.wages).replace('{count}', display.wages.map(count).join(' / '));
    }
}

/** "From 1 Jan 2025 to 30 Jun 2026", or "From 1 Jul 2026" for the one still in force. */
export function describePeriod(
    version: Pick<ParameterVersion, 'validFrom' | 'validUntil'>,
    t: Translate,
    locale: string,
): string {
    return (
        version.validUntil === null ? t((d) => d.pages.taxRules.periodOpen) : t((d) => d.pages.taxRules.periodClosed)
    )
        .replace('{from}', formatDate(version.validFrom, locale))
        .replace('{until}', version.validUntil === null ? '' : formatDate(version.validUntil, locale));
}

/** Something found out while reading, in the reader's language, what it is about filled in — a day as a day. */
export function describeCheck(
    check: { kind: string; detail: Record<string, string | number> },
    t: Translate,
    locale: string,
): string {
    let text = t((d) => d.pages.taxRules.checks)[check.kind] ?? check.kind;

    for (const [key, value] of Object.entries(check.detail)) {
        // As given, but for a day: most numbers are years, which a number format would write "2.017".
        const shown =
            typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value, locale) : String(value);
        text = text.replaceAll(`{${key}}`, shown);
    }

    return text;
}
