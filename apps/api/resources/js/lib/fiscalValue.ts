import type { Dictionary } from '../i18n/dictionary';
import type { ParameterType, ParameterVersion } from '../types/fiscalParameters';
import { formatAmount, formatDate, formatDayMonth, formatPercent } from './format';

type Translate = <T>(pick: (d: Dictionary) => T) => T;

/**
 * A fiscal value as a person reads it, from how it is stored: the minimum wage an amount, a rate a
 * percent, a threshold so many minimum wages. One place, so the table and the detail agree.
 */
export function describeFiscalValue(
    type: ParameterType,
    value: Record<string, unknown>,
    t: Translate,
    locale: string,
): string {
    if (type === 'MONEY') {
        const minor = typeof value.amount_minor === 'number' ? value.amount_minor : 0;

        // The currency is stored with the amount; one missing shows the bare number rather than a guess.
        return typeof value.currency === 'string'
            ? formatAmount(minor / 100, value.currency, locale)
            : String(minor / 100);
    }

    if (type === 'DECIMAL') {
        return formatPercent(Number(typeof value.value === 'string' ? value.value : 0), locale);
    }

    // A deadline the law repeats every year: the day and the month, the year being the one after.
    if (typeof value.day === 'number' && typeof value.month === 'number') {
        return formatDayMonth(value.day, value.month, locale);
    }

    // A base with two ends — not less than six wages, not more than seventy-two — reads as a range.
    // Both ends the same is a base that cannot move, which the law set as one level: it reads as one.
    if (typeof value.floor === 'number' || typeof value.cap === 'number') {
        const end = (one: unknown): string => (typeof one === 'number' ? String(one) : '—');

        return value.floor === value.cap
            ? t((d) => d.pages.taxRules.wages).replace('{count}', end(value.floor))
            : t((d) => d.pages.taxRules.wagesRange)
                  .replace('{floor}', end(value.floor))
                  .replace('{cap}', end(value.cap));
    }

    const wages = type === 'JSON' ? (Array.isArray(value.wages) ? value.wages : []) : [value.value];

    return t((d) => d.pages.taxRules.wages).replace(
        '{count}',
        wages.filter((entry): entry is number => typeof entry === 'number').join(' / '),
    );
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

/** What needs a second look is a warning; what only informs is not. */
export function checkTone(check: { kind: string }): 'warn' | 'idle' {
    return check.kind === 'several_paragraphs' || check.kind === 'dated_by_note' || check.kind === 'in_force_later'
        ? 'idle'
        : 'warn';
}
