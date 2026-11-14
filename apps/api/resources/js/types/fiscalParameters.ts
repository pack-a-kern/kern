import { nullableStr, num, obj, str } from '../lib/json';

/**
 * Shapes returned by `/api/v1/fiscal-parameters` — the values a tax is computed from, each as read
 * from an official text, with the paragraph of the law it rests on.
 */

export type ParameterType = 'MONEY' | 'DECIMAL' | 'INTEGER' | 'JSON';
export type ParameterStatus = 'CANDIDATE' | 'APPROVED' | 'REJECTED';

/** A paragraph of the law, as shown beside a value. */
export interface LawParagraph {
    sectionId: string;
    citation: string;
    lawId: string | null;
    /** The day the consolidation it is in applies from — to open the law as it stood. */
    on: string | null;
    excerpt: string;
    /** The numbers of the value it says. Empty for a value's own source when it is not among those found. */
    says: number[];
}

/** What a value was read from: a paragraph of the law, or a decision's paragraph for the minimum wage. */
export type ParameterReading =
    | { kind: 'law'; model: string; citation: string; quote: string }
    | { kind: 'decision'; lawId: string; act: string; citation: string; quote: string };

/** Something found out while reading, for the person approving — `kind`, what it is about, and whether it wants a second look. */
export interface ParameterCheck {
    kind: string;
    detail: Record<string, string | number>;
    tone: 'warn' | 'idle';
}

/** A value as the server says it is shown — its kind and the fields that kind is formatted from. */
export type FiscalValueDisplay =
    | { kind: 'money'; amountMinor: number; currency: string | null }
    | { kind: 'percent'; ratio: string }
    | { kind: 'day_month'; day: number; month: number }
    | { kind: 'flag'; holds: boolean }
    | { kind: 'days'; days: number }
    | { kind: 'wages'; wages: (number | null)[] }
    | { kind: 'wages_range'; floor: number | null; cap: number | null };

export interface ParameterVersion {
    id: string;
    status: ParameterStatus;
    validFrom: string;
    validUntil: string | null;
    display: FiscalValueDisplay;
    source: LawParagraph | null;
    matches: LawParagraph[];
    readings: ParameterReading[];
    checks: ParameterCheck[];
    approvedAt: string | null;
}

/** Who a value is about: what a person owes, what a company owes, or both. */
export type ParameterSubject = 'PERSON' | 'COMPANY' | 'BOTH';

/** A year the law was read for a value and gave none. */
export interface ParameterRead {
    year: number;
    /** NOT_IN_LAW — that year's law does not state it; UNREAD — no model could be asked. */
    outcome: 'NOT_IN_LAW' | 'UNREAD';
    readAt: string;
}

export interface FiscalParameter {
    code: string;
    /** The tax it belongs to — `RO.INCOME_TAX` — whose rules are shown together. */
    tax: string;
    type: ParameterType;
    subject: ParameterSubject;
    versions: ParameterVersion[];
    reads: ParameterRead[];
}

export interface FiscalParameterBoard {
    parameters: FiscalParameter[];
    /**
     * What the screen leads with, worked out by the server: the minimum wage's code and its history in order
     * — each period's bar against the largest — how many values wait for review, and the years read, newest first.
     */
    summary: {
        wage: string | null;
        wageInForce: string | null;
        wageHistory: { id: string; bar: string }[];
        rules: number;
        waiting: number;
        years: number[];
        yearsByTax: Record<string, number[]>;
    };
    /** The years the law can be read for. */
    years: number[];
}

function list(value: unknown): unknown[] {
    return Array.isArray(value) ? value : [];
}

/** Every scalar beside `kind`, which is what a check or a miss is about. */
function detail(row: Record<string, unknown>): Record<string, string | number> {
    const found: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(row)) {
        if (key !== 'kind' && key !== 'parameter' && (typeof value === 'string' || typeof value === 'number')) {
            found[key] = value;
        }
    }

    return found;
}

function paragraph(value: unknown): LawParagraph | null {
    const row = obj(value);

    return str(row, 'section_id') === ''
        ? null
        : {
              sectionId: str(row, 'section_id'),
              citation: str(row, 'citation'),
              lawId: nullableStr(row, 'law_id'),
              on: nullableStr(row, 'on'),
              excerpt: str(row, 'excerpt'),
              says: list(row.says).filter((entry): entry is number => typeof entry === 'number'),
          };
}

function reading(value: unknown): ParameterReading | null {
    const row = obj(value);

    switch (row.kind) {
        case 'law':
            return {
                kind: 'law',
                model: str(row, 'model'),
                citation: str(row, 'citation'),
                quote: str(row, 'quote'),
            };
        case 'decision':
            return {
                kind: 'decision',
                lawId: str(row, 'law_id'),
                act: str(row, 'act'),
                citation: str(row, 'citation'),
                quote: str(row, 'quote'),
            };
        default:
            return null;
    }
}

function status(value: unknown): ParameterStatus {
    return value === 'APPROVED' || value === 'REJECTED' ? value : 'CANDIDATE';
}

function type(value: unknown): ParameterType {
    return value === 'MONEY' || value === 'DECIMAL' || value === 'INTEGER' ? value : 'JSON';
}

function subject(value: unknown): ParameterSubject {
    return value === 'COMPANY' || value === 'BOTH' ? value : 'PERSON';
}

/** A number, or null for a field the value does not state. */
function maybeNumber(value: unknown): number | null {
    return typeof value === 'number' ? value : null;
}

function display(row: Record<string, unknown>): FiscalValueDisplay {
    switch (row.kind) {
        case 'money':
            return { kind: 'money', amountMinor: num(row, 'amount_minor'), currency: nullableStr(row, 'currency') };
        case 'percent':
            return { kind: 'percent', ratio: str(row, 'ratio') };
        case 'day_month':
            return { kind: 'day_month', day: num(row, 'day'), month: num(row, 'month') };
        case 'flag':
            return { kind: 'flag', holds: row.holds === true };
        case 'days':
            return { kind: 'days', days: num(row, 'days') };
        case 'wages_range':
            return { kind: 'wages_range', floor: maybeNumber(row.floor), cap: maybeNumber(row.cap) };
        case 'wages':
            return { kind: 'wages', wages: list(row.wages).map(maybeNumber) };
        default:
            throw new TypeError(`Unknown fiscal value kind: ${String(row.kind)}`);
    }
}

export function parseFiscalParameterBoard(payload: unknown): FiscalParameterBoard {
    const body = obj(payload);

    const summary = obj(body.summary);

    return {
        summary: {
            wage: nullableStr(summary, 'wage'),
            wageInForce: nullableStr(summary, 'wage_in_force'),
            wageHistory: list(summary.wage_history).map((entry) => ({
                id: str(obj(entry), 'id'),
                bar: str(obj(entry), 'bar'),
            })),
            rules: num(summary, 'rules'),
            waiting: num(summary, 'waiting'),
            years: list(summary.years).filter((year): year is number => typeof year === 'number'),
            yearsByTax: Object.fromEntries(
                Object.entries(obj(summary.years_by_tax)).map(([tax, years]) => [
                    tax,
                    list(years).filter((year): year is number => typeof year === 'number'),
                ]),
            ),
        },
        years: list(body.years).filter((year): year is number => typeof year === 'number'),
        parameters: list(body.parameters).map((entry): FiscalParameter => {
            const row = obj(entry);

            return {
                code: str(row, 'code'),
                tax: str(row, 'tax'),
                type: type(row.type),
                subject: subject(row.subject),
                versions: list(row.versions).map((item): ParameterVersion => {
                    const version = obj(item);

                    return {
                        id: str(version, 'id'),
                        status: status(version.status),
                        validFrom: str(version, 'valid_from'),
                        validUntil: nullableStr(version, 'valid_until'),
                        display: display(obj(version.display)),
                        source: paragraph(version.source),
                        matches: list(version.matches).flatMap((match) => {
                            const found = paragraph(match);

                            return found === null ? [] : [found];
                        }),
                        readings: list(version.readings).flatMap((match) => {
                            const found = reading(match);

                            return found === null ? [] : [found];
                        }),
                        checks: list(version.checks).map((check) => ({
                            kind: str(obj(check), 'kind'),
                            detail: detail(obj(check)),
                            tone: obj(check).tone === 'idle' ? 'idle' : 'warn',
                        })),
                        approvedAt: nullableStr(version, 'approved_at'),
                    };
                }),
                reads: list(row.reads).map((entry): ParameterRead => {
                    const read = obj(entry);

                    return {
                        year: Number(read.year),
                        outcome: read.outcome === 'UNREAD' ? 'UNREAD' : 'NOT_IN_LAW',
                        readAt: str(read, 'read_at'),
                    };
                }),
            };
        }),
    };
}

/** The next Declarația unică and the day it is due — the `filingDeadline` page prop. */
export interface FilingDeadline {
    /** The year whose income it declares. */
    incomeYear: number;
    dueOn: string;
    /** Whole days until it, counted on Romania's calendar; 0 on the day itself. */
    daysLeft: number;
}

/** Null where no approved value says when the next one is due. */
export function parseFilingDeadline(deadline: unknown): FilingDeadline | null {
    if (deadline === null || typeof deadline !== 'object') {
        return null;
    }

    const row = obj(deadline);

    return { incomeYear: num(row, 'income_year'), dueOn: str(row, 'due_on'), daysLeft: num(row, 'days_left') };
}
