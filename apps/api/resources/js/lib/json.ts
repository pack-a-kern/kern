/**
 * Reading a decoded JSON body field by field, without trusting its shape.
 *
 * Every `types/*.ts` parser narrows `unknown` from the API into the shape a screen uses, and each
 * one used to carry its own copy of these — some forty, in a dozen files. A field of the wrong type
 * reads as the empty value of the right one, so a screen renders a blank rather than crashing on a
 * response it did not expect.
 */

type Row = Record<string, unknown>;

/** An object, or an empty one — never an array, which is not a row whatever `typeof` says. */
export function obj(value: unknown): Row {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Row) : {};
}

export function str(row: Row, key: string): string {
    const value = row[key];

    return typeof value === 'string' ? value : '';
}

export function nullableStr(row: Row, key: string): string | null {
    const value = row[key];

    return typeof value === 'string' ? value : null;
}

export function num(row: Row, key: string): number {
    const value = row[key];

    return typeof value === 'number' ? value : 0;
}

export function nullableNum(row: Row, key: string): number | null {
    const value = row[key];

    return typeof value === 'number' ? value : null;
}

/** True only for `true`: a missing flag, or a `"true"` string, is not a yes. */
export function bool(row: Row, key: string): boolean {
    return row[key] === true;
}
