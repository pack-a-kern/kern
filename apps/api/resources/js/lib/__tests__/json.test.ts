import { describe, expect, it } from 'vitest';

import { bool, nullableNum, nullableStr, num, obj, str } from '../json';

describe('obj', () => {
    it('keeps an object, and makes anything else an empty one', () => {
        expect(obj({ a: 1 })).toEqual({ a: 1 });
        expect(obj(null)).toEqual({});
        expect(obj('text')).toEqual({});
    });

    it('does not take a list for a row', () => {
        expect(obj([1, 2])).toEqual({});
    });
});

describe('field readers', () => {
    const row = { name: 'Ana', count: 3, flag: true, word: 'true', empty: null };

    it('read a field of the right type as it is', () => {
        expect(str(row, 'name')).toBe('Ana');
        expect(nullableStr(row, 'name')).toBe('Ana');
        expect(num(row, 'count')).toBe(3);
        expect(nullableNum(row, 'count')).toBe(3);
        expect(bool(row, 'flag')).toBe(true);
    });

    it('read a missing or mistyped field as the empty value of the right type', () => {
        expect(str(row, 'count')).toBe('');
        expect(nullableStr(row, 'missing')).toBeNull();
        expect(num(row, 'name')).toBe(0);
        expect(nullableNum(row, 'empty')).toBeNull();
        expect(bool(row, 'word')).toBe(false);
        expect(bool(row, 'missing')).toBe(false);
    });
});
