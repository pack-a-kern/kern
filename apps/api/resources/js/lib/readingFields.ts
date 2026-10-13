import type { DocumentExtractionSummary } from '../types/documents';
import { BASE_CURRENCY } from '../types/wallet';

/*
 * Reading values out of a document's proposal, for the forms a document pre-fills.
 *
 * `proposedFields` is `unknown` by design — a model answers whatever it likes — so every read here
 * tolerates a missing or malformed value and yields the empty answer instead.
 */

/** One string out of a reading, or ''. */
export function readString(fields: Record<string, unknown>, name: string): string {
    const value = fields[name];

    return typeof value === 'string' ? value.trim() : '';
}

/** A nested group (`issuer`, `recipient`) as a flat record. Absent or malformed reads as {}. */
export function groupOf(fields: Record<string, unknown>, name: string): Record<string, unknown> {
    const value = fields[name];

    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

/** One entry of a list field, as a flat record. Absent, empty or malformed all read as {}. */
export function entryAt(fields: Record<string, unknown>, name: string, index: number): Record<string, unknown> {
    const value = fields[name];
    const entry: unknown = Array.isArray(value) ? value[index] : null;

    return typeof entry === 'object' && entry !== null && !Array.isArray(entry)
        ? (entry as Record<string, unknown>)
        : {};
}

/**
 * The payment a form is about: the first one the subject is actually a party to.
 *
 * Not simply the first row. A dividend decision lists every associate, and the first of them is
 * usually somebody else — so a form opened pre-filled with a stranger's name and their money.
 *
 * `paymentParties` is the server's answer to "which of these are yours", and a null direction is
 * how it says "none of your business". Falling back to row zero when *nothing* matches is
 * deliberate: a document whose parties could not be matched still has to be usable by hand.
 */
export function subjectPaymentIndex(extraction: DocumentExtractionSummary | null): number {
    const match = extraction?.paymentParties.findIndex((party) => party.direction !== null) ?? -1;

    return match === -1 ? 0 : match;
}

/**
 * The payment's own currency where it is one this installation offers, else the base currency.
 *
 * The offered list is a parameter because it is a setting now, and this module is read by form
 * builders that run outside a component where no hook can be called. The caller has it from
 * `useCurrencies()` and passes it down.
 */
export function currencyFrom(payment: Record<string, unknown>, offered: string[]): string {
    const read = readString(payment, 'currency_paid').toUpperCase();

    return offered.includes(read) ? read : BASE_CURRENCY;
}
