import { describe, expect, it } from 'vitest';

import { formatBytes, formatDateTime } from '../format';

describe('formatBytes', () => {
    it('uses kilobytes below a megabyte', () => {
        expect(formatBytes(2048, 'en-GB')).toContain('2');
        expect(formatBytes(2048, 'en-GB')).toMatch(/kB|KB/i);
    });

    // A 4 MB scan reading as "4,096 KB" is technically right and useless.
    it('steps up to megabytes at 1024 KB', () => {
        expect(formatBytes(4 * 1024 * 1024, 'en-GB')).toMatch(/MB/i);
    });

    it('formats the number in the given locale', () => {
        // Romanian uses a comma as the decimal separator; English a full stop.
        const ro = formatBytes(1.5 * 1024 * 1024, 'ro-RO');
        const en = formatBytes(1.5 * 1024 * 1024, 'en-GB');

        expect(ro).toContain('1,5');
        expect(en).toContain('1.5');
    });

    it('falls back to the raw byte count rather than throwing', () => {
        expect(formatBytes(1024, 'not-a-locale')).toBeTypeOf('string');
    });
});

describe('formatDateTime', () => {
    it('returns the input unchanged when it is not a date', () => {
        expect(formatDateTime('not a date', 'en-GB')).toBe('not a date');
    });

    it('formats a real timestamp', () => {
        expect(formatDateTime('2026-03-02T09:00:00Z', 'en-GB')).toContain('2026');
    });
});
