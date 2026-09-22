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

/** What a value was read from: a form's formulas, a decision's paragraph, or last year's approved value. */
export type ParameterReading =
    | { kind: 'form'; form: string; url: string; formYear: number; quotes: string[] }
    | { kind: 'decision'; lawId: string; act: string; citation: string; quote: string }
    | { kind: 'carried'; fromYear: number };

/** Something found out while reading, for the person approving — `kind` and what it is about. */
export interface ParameterCheck {
    kind: string;
    detail: Record<string, string | number>;
}

export interface ParameterVersion {
    id: string;
    status: ParameterStatus;
    validFrom: string;
    validUntil: string | null;
    value: Record<string, unknown>;
    source: LawParagraph | null;
    matches: LawParagraph[];
    readings: ParameterReading[];
    checks: ParameterCheck[];
    approvedAt: string | null;
}

export interface FiscalParameter {
    code: string;
    type: ParameterType;
    versions: ParameterVersion[];
}

/** What a form says that the law was searched for and not found to say. */
export interface UnmatchedReading {
    parameter: string;
    kind: string;
    detail: Record<string, string | number>;
}

export interface FormStructure {
    id: string;
    form: string;
    year: number;
    url: string;
    contentHash: string;
    readAt: string;
    unmatched: UnmatchedReading[];
}

export interface FiscalParameterBoard {
    parameters: FiscalParameter[];
    forms: FormStructure[];
}

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

function list(value: unknown): unknown[] {
    return Array.isArray(value) ? value : [];
}

function str(row: Record<string, unknown>, key: string): string {
    const value = row[key];

    return typeof value === 'string' ? value : '';
}

function nullableStr(row: Record<string, unknown>, key: string): string | null {
    const value = row[key];

    return typeof value === 'string' ? value : null;
}

function num(row: Record<string, unknown>, key: string): number {
    const value = row[key];

    return typeof value === 'number' ? value : 0;
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
        case 'form':
            return {
                kind: 'form',
                form: str(row, 'form'),
                url: str(row, 'url'),
                formYear: num(row, 'form_year'),
                quotes: list(row.quotes).filter((entry): entry is string => typeof entry === 'string'),
            };
        case 'decision':
            return {
                kind: 'decision',
                lawId: str(row, 'law_id'),
                act: str(row, 'act'),
                citation: str(row, 'citation'),
                quote: str(row, 'quote'),
            };
        case 'carried':
            return { kind: 'carried', fromYear: num(row, 'from_year') };
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

export function parseFiscalParameterBoard(payload: unknown): FiscalParameterBoard {
    const body = obj(payload);

    return {
        parameters: list(body.parameters).map((entry): FiscalParameter => {
            const row = obj(entry);

            return {
                code: str(row, 'code'),
                type: type(row.type),
                versions: list(row.versions).map((item): ParameterVersion => {
                    const version = obj(item);

                    return {
                        id: str(version, 'id'),
                        status: status(version.status),
                        validFrom: str(version, 'valid_from'),
                        validUntil: nullableStr(version, 'valid_until'),
                        value: obj(version.value),
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
                        })),
                        approvedAt: nullableStr(version, 'approved_at'),
                    };
                }),
            };
        }),
        forms: list(body.forms).map((entry): FormStructure => {
            const row = obj(entry);

            return {
                id: str(row, 'id'),
                form: str(row, 'form'),
                year: num(row, 'year'),
                url: str(row, 'url'),
                contentHash: str(row, 'content_hash'),
                readAt: str(row, 'read_at'),
                unmatched: list(row.unmatched).map((miss) => ({
                    parameter: str(obj(miss), 'parameter'),
                    kind: str(obj(miss), 'kind'),
                    detail: detail(obj(miss)),
                })),
            };
        }),
    };
}
