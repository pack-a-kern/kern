/**
 * Minimal, dependency-free runtime validation for API-boundary data (`BLUEPRINT.md` §19.5: "runtime
 * validation at every API boundary", `docs/DEPENDENCY-POLICY.md` prefers the standard library over
 * a third-party package where the standard library is genuinely enough — a handful of small
 * combinators covers the shapes this app receives from `apps/api`'s JSON endpoints without pulling
 * in a schema library).
 *
 * Every parser is a `Validator<T>`: throws {@link ValidationError} on a mismatch, returns a typed
 * value otherwise. Compose small validators into larger ones with {@link vObject}/{@link vArray}.
 */

export class ValidationError extends Error {
    constructor(
        public readonly path: string,
        message: string,
    ) {
        super(`${path || '<root>'}: ${message}`);
        this.name = 'ValidationError';
    }
}

export type Validator<T> = (value: unknown, path?: string) => T;

function fail(path: string, message: string): never {
    throw new ValidationError(path, message);
}

export const vString: Validator<string> = (value, path = '') => {
    if (typeof value !== 'string') {
        fail(path, `expected a string, got ${typeof value}`);
    }

    return value;
};

export const vNumber: Validator<number> = (value, path = '') => {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        fail(path, `expected a number, got ${typeof value}`);
    }

    return value;
};

export const vBoolean: Validator<boolean> = (value, path = '') => {
    if (typeof value !== 'boolean') {
        fail(path, `expected a boolean, got ${typeof value}`);
    }

    return value;
};

export function vLiteral<T extends string>(literal: T): Validator<T> {
    return (value, path = '') => {
        if (value !== literal) {
            fail(path, `expected literal ${literal}`);
        }

        return literal;
    };
}

export function vEnum<T extends string>(values: readonly T[]): Validator<T> {
    return (value, path = '') => {
        if (typeof value !== 'string' || !(values as readonly string[]).includes(value)) {
            fail(path, `expected one of [${values.join(', ')}], got ${JSON.stringify(value)}`);
        }

        return value as T;
    };
}

export function vNullable<T>(inner: Validator<T>): Validator<T | null> {
    return (value, path = '') => (value === null ? null : inner(value, path));
}

export function vOptional<T>(inner: Validator<T>): Validator<T | undefined> {
    return (value, path = '') => (value === undefined ? undefined : inner(value, path));
}

export function vArray<T>(inner: Validator<T>): Validator<T[]> {
    return (value, path = '') => {
        if (!Array.isArray(value)) {
            fail(path, `expected an array, got ${typeof value}`);
        }

        return value.map((item: unknown, index: number) => inner(item, `${path}[${String(index)}]`));
    };
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function vObject<T extends Record<string, unknown>>(shape: { [K in keyof T]: Validator<T[K]> }): Validator<T> {
    return (value, path = '') => {
        if (!isRecord(value)) {
            fail(path, `expected an object, got ${typeof value}`);
        }

        const result = {} as T;

        for (const key of Object.keys(shape) as (keyof T)[]) {
            const validator = shape[key];
            const childPath = path ? `${path}.${String(key)}` : String(key);
            result[key] = validator(value[key as string], childPath);
        }

        return result;
    };
}

/**
 * Accepts any JSON object without validating its keys/values — used for payloads this codebase
 * has no fixed schema for (`document_extractions.result_ciphertext`; see
 * `Kern\Documents\Review\CorrectDocumentExtraction`'s own docblock: `BLUEPRINT.md` §14.4
 * describes a per-document-type JSON schema, but none exists yet, and inventing one client-side
 * would be exactly the fabrication `docs/AGENTS.md` §2 forbids). Callers must render every value
 * as inert text/data — never `dangerouslySetInnerHTML`, never `eval`, never treated as markup or
 * instructions — because this content originates from an AI proposal or a document upload, both
 * untrusted (`BLUEPRINT.md` §14.5).
 */
export const vRecord: Validator<Record<string, unknown>> = (value, path = '') => {
    if (!isRecord(value)) {
        fail(path, `expected an object, got ${typeof value}`);
    }

    return value;
};

/**
 * The cursor-pagination envelope every list endpoint in this codebase uses (`BLUEPRINT.md` §18):
 * `{"data": [...], "next_cursor": ..., "prev_cursor": ...}` plus framework-internal fields
 * (`path`, `per_page`, `next_page_url`, `prev_page_url`) this app has no use for and therefore
 * does not validate.
 */
export interface CursorPage<T> {
    data: T[];
    nextCursor: string | null;
    prevCursor: string | null;
}

export function vCursorPage<T>(item: Validator<T>): Validator<CursorPage<T>> {
    return (value, path = '') => {
        const parsed = vObject({
            data: vArray(item),
            next_cursor: vNullable(vString),
            prev_cursor: vNullable(vString),
        })(value, path);

        return { data: parsed.data, nextCursor: parsed.next_cursor, prevCursor: parsed.prev_cursor };
    };
}

/**
 * The numbered-pagination envelope, for endpoints a person pages through by hand rather than
 * scrolls. Carries the total, which a cursor cannot: "page 7 of 12" and "1–25 of 300" are both
 * questions about a set, and a cursor only knows about the row it points at.
 */
export interface NumberedPage<T> {
    data: T[];
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

export function vNumberedPage<T>(item: Validator<T>): Validator<NumberedPage<T>> {
    return (value, path = '') => {
        const parsed = vObject({
            data: vArray(item),
            current_page: vNumber,
            per_page: vNumber,
            total: vNumber,
            last_page: vNumber,
        })(value, path);

        return {
            data: parsed.data,
            page: parsed.current_page,
            perPage: parsed.per_page,
            total: parsed.total,
            lastPage: parsed.last_page,
        };
    };
}

/** Parses `text` as JSON, then validates it with `validator`. Throws on malformed JSON too. */
export function parseJson<T>(text: string, validator: Validator<T>): T {
    let raw: unknown;

    try {
        raw = text === '' ? null : JSON.parse(text);
    } catch {
        throw new ValidationError('', 'response body was not valid JSON');
    }

    return validator(raw);
}

export { isRecord };
