import { describe, expect, it } from 'vitest';

import { addMonths, daysBetween } from '../depositTerm';

describe('addMonths', () => {
    it('adds whole months', () => {
        expect(addMonths('2026-01-15', 6)).toBe('2026-07-15');
    });

    it('carries into the next year', () => {
        expect(addMonths('2026-09-09', 12)).toBe('2027-09-09');
        expect(addMonths('2026-11-30', 3)).toBe('2027-02-28');
    });

    // The case `Date.setMonth` gets wrong: it would roll 31 January forward to 3 March, and the
    // deposit would be paid interest for days it never ran.
    it('clamps to the last day of a shorter month', () => {
        expect(addMonths('2026-01-31', 1)).toBe('2026-02-28');
        expect(addMonths('2026-08-31', 1)).toBe('2026-09-30');
    });

    it('clamps to 29 February in a leap year', () => {
        expect(addMonths('2024-01-31', 1)).toBe('2024-02-29');
    });

    it('is blank for a half-typed or malformed date', () => {
        expect(addMonths('2026-01', 1)).toBe('');
        expect(addMonths('', 1)).toBe('');
    });
});

describe('daysBetween', () => {
    it('counts whole days', () => {
        expect(daysBetween('2026-01-01', '2026-01-31')).toBe(30);
        expect(daysBetween('2026-01-15', '2027-01-15')).toBe(365);
    });

    // A term crossing the March and October transitions must still be a whole number of days —
    // the reason both endpoints are built as UTC rather than local midnight.
    it('is unaffected by daylight saving', () => {
        expect(daysBetween('2026-03-01', '2026-04-01')).toBe(31);
        expect(daysBetween('2026-10-01', '2026-11-01')).toBe(31);
    });

    it('is null when either endpoint is not a date', () => {
        expect(daysBetween('', '2026-01-01')).toBeNull();
        expect(daysBetween('2026-01-01', 'soon')).toBeNull();
    });
});
