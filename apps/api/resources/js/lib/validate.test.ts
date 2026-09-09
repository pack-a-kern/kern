import { describe, expect, it } from 'vitest';
import {
    ValidationError,
    parseJson,
    vArray,
    vBoolean,
    vCursorPage,
    vEnum,
    vNullable,
    vNumber,
    vObject,
    vOptional,
    vRecord,
    vString,
} from './validate';

describe('primitive validators', () => {
    it('accepts a matching primitive', () => {
        expect(vString('hello')).toBe('hello');
        expect(vNumber(42)).toBe(42);
        expect(vBoolean(true)).toBe(true);
    });

    it('rejects a mismatched type', () => {
        expect(() => vString(42)).toThrow(ValidationError);
        expect(() => vNumber('42')).toThrow(ValidationError);
        expect(() => vBoolean(1)).toThrow(ValidationError);
    });

    it('rejects NaN as a number', () => {
        expect(() => vNumber(Number.NaN)).toThrow(ValidationError);
    });
});

describe('vEnum', () => {
    const status = vEnum(['READY', 'REJECTED'] as const);

    it('accepts a listed value', () => {
        expect(status('READY')).toBe('READY');
    });

    it('rejects an unlisted value', () => {
        expect(() => status('UNKNOWN')).toThrow(ValidationError);
    });
});

describe('vNullable / vOptional', () => {
    it('passes null through without invoking the inner validator', () => {
        expect(vNullable(vString)(null)).toBeNull();
    });

    it('still validates a non-null value', () => {
        expect(() => vNullable(vString)(42)).toThrow(ValidationError);
    });

    it('passes undefined through for vOptional', () => {
        expect(vOptional(vString)(undefined)).toBeUndefined();
    });
});

describe('vArray', () => {
    it('validates every element and reports the failing index in the path', () => {
        expect(vArray(vString)(['a', 'b'])).toEqual(['a', 'b']);

        try {
            vArray(vString)(['a', 42], 'items');
            expect.fail('expected a ValidationError');
        } catch (error) {
            expect(error).toBeInstanceOf(ValidationError);
            expect((error as ValidationError).path).toBe('items[1]');
        }
    });
});

describe('vObject', () => {
    const parseUser = vObject({ id: vString, age: vNumber });

    it('builds a typed object from matching keys', () => {
        expect(parseUser({ id: 'u1', age: 30 })).toEqual({ id: 'u1', age: 30 });
    });

    it('rejects a non-object', () => {
        expect(() => parseUser('nope')).toThrow(ValidationError);
    });

    it('reports a dotted path for a nested failure', () => {
        const parseWrapper = vObject({ user: parseUser });

        try {
            parseWrapper({ user: { id: 'u1', age: 'thirty' } });
            expect.fail('expected a ValidationError');
        } catch (error) {
            expect((error as ValidationError).path).toBe('user.age');
        }
    });
});

describe('vRecord', () => {
    it('accepts any JSON object without validating its keys', () => {
        expect(vRecord({ anything: 42, nested: { a: 'b' } })).toEqual({ anything: 42, nested: { a: 'b' } });
    });

    it('rejects an array', () => {
        expect(() => vRecord([1, 2])).toThrow(ValidationError);
    });

    it('rejects a primitive', () => {
        expect(() => vRecord('nope')).toThrow(ValidationError);
        expect(() => vRecord(null)).toThrow(ValidationError);
    });
});

describe('vCursorPage', () => {
    const page = vCursorPage(vObject({ id: vString }));

    it('parses the data array and both cursors', () => {
        expect(page({ data: [{ id: 'a' }, { id: 'b' }], next_cursor: 'n1', prev_cursor: null })).toEqual({
            data: [{ id: 'a' }, { id: 'b' }],
            nextCursor: 'n1',
            prevCursor: null,
        });
    });

    it('validates every item with the inner validator', () => {
        expect(() => page({ data: [{ id: 42 }], next_cursor: null, prev_cursor: null })).toThrow(ValidationError);
    });

    it('rejects a missing cursor field', () => {
        expect(() => page({ data: [] })).toThrow(ValidationError);
    });
});

describe('parseJson', () => {
    it('parses and validates in one pass', () => {
        expect(parseJson('{"id":"u1"}', vObject({ id: vString }))).toEqual({ id: 'u1' });
    });

    it('throws ValidationError on malformed JSON, not a raw SyntaxError', () => {
        expect(() => parseJson('{not json', vObject({}))).toThrow(ValidationError);
    });

    it('treats an empty body as null before validation', () => {
        expect(() => parseJson('', vString)).toThrow(ValidationError);
    });
});
