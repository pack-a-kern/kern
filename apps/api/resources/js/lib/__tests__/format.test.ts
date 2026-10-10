import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { formatBytes, formatDateTime, formatWhen } from '../format';

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

describe('formatWhen', () => {
    /** Relative to a fixed now, so "2 hours ago" means the same thing on every run. */
    const NOW = new Date('2026-03-02T12:00:00Z');

    const ago = (seconds: number): string => new Date(NOW.getTime() - seconds * 1000).toISOString();

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('counts in the largest unit that still has a whole number in it', () => {
        expect(formatWhen(ago(30), 'en-GB')).toMatch(/second/);
        expect(formatWhen(ago(90 * 60), 'en-GB')).toMatch(/hour/);
        expect(formatWhen(ago(3 * 24 * 3600), 'en-GB')).toMatch(/day/);
    });

    // `numeric: 'auto'` is there for this: a language with a word for the day before should use it.
    it('says yesterday rather than one day ago', () => {
        expect(formatWhen(ago(24 * 3600), 'en-GB')).toBe('yesterday');
        expect(formatWhen(ago(24 * 3600), 'ro')).toBe('ieri');
    });

    it('speaks the given language', () => {
        expect(formatWhen(ago(2 * 3600), 'ro')).toContain('ore');
        expect(formatWhen(ago(2 * 3600), 'en-GB')).toContain('hours');
    });

    /* Past a week the phrase stops being an answer — nobody reads "27 days ago" as a date. */
    it('gives a date once the event is older than a week', () => {
        const older = formatWhen(ago(30 * 24 * 3600), 'en-GB');

        expect(older).toContain('2026');
        expect(older).not.toMatch(/ago/);
    });

    it('still works for something that has not happened yet', () => {
        const soon = new Date(NOW.getTime() + 3 * 3600 * 1000).toISOString();

        expect(formatWhen(soon, 'en-GB')).toMatch(/in 3 hours/);
    });

    it('returns the input unchanged rather than rendering NaN', () => {
        expect(formatWhen('not a date', 'en-GB')).toBe('not a date');
    });
});
