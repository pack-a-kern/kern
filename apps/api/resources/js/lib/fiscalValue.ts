import type { Dictionary } from '../i18n/dictionary';
import type { ParameterType, ParameterVersion } from '../types/fiscalParameters';
import { formatAmount, formatDate, formatPercent } from './format';

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
        version.validUntil === null
            ? t((d) => d.pages.taxRules.periodOpen)
            : t((d) => d.pages.taxRules.periodClosed)
    )
        .replace('{from}', formatDate(version.validFrom, locale))
        .replace('{until}', version.validUntil === null ? '' : formatDate(version.validUntil, locale));
}

/** Something found out while reading, in the reader's language, what it is about filled in. */
export function describeCheck(check: { kind: string; detail: Record<string, string | number> }, t: Translate): string {
    let text = t((d) => d.pages.taxRules.checks)[check.kind] ?? check.kind;

    for (const [key, value] of Object.entries(check.detail)) {
        // As given: most are years, which a number format would write "2.017".
        text = text.replaceAll(`{${key}}`, String(value));
    }

    return text;
}

/** What needs a second look is a warning; what only informs is not. */
export function checkTone(check: { kind: string }): 'ok' | 'warn' | 'idle' {
    return check.kind === 'confirmed_by_form' ? 'ok' : check.kind === 'several_paragraphs' ? 'idle' : 'warn';
}
