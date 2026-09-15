import { describe, expect, it } from 'vitest';

import { addMonths, daysBetween, maturityFor, nextWorkingDay, termMonths } from '../depositTerm';

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

describe('nextWorkingDay', () => {
    it('leaves a weekday alone', () => {
        // Tuesday 1 September 2026.
        expect(nextWorkingDay('2026-09-01')).toBe('2026-09-01');
    });

    it('moves a Saturday or a Sunday to the Monday after', () => {
        expect(nextWorkingDay('2027-05-01')).toBe('2027-05-03');
        expect(nextWorkingDay('2027-05-02')).toBe('2027-05-03');
    });

    it('crosses into the next month and year when the weekend does', () => {
        expect(nextWorkingDay('2026-10-31')).toBe('2026-11-02');
        expect(nextWorkingDay('2028-12-31')).toBe('2029-01-01');
    });

    it('returns an empty string for a half-typed date', () => {
        expect(nextWorkingDay('2026-09')).toBe('');
    });
});

describe('maturityFor', () => {
    it('is the term end when that is a working day', () => {
        // 1 September 2027 is a Wednesday.
        expect(maturityFor('2026-09-01', 12)).toBe('2027-09-01');
    });

    it('moves a term ending on a weekend to the Monday', () => {
        // 1 May 2027 is a Saturday.
        expect(maturityFor('2026-05-01', 12)).toBe('2027-05-03');
    });
});

describe('termMonths', () => {
    it('reads a moved maturity back as the term it was bought for', () => {
        // Five months from 31 May 2026 ends on Saturday 31 October, maturing Monday 2 November —
        // six calendar months away, and still a five-month term.
        expect(maturityFor('2026-05-31', 5)).toBe('2026-11-02');
        expect(termMonths('2026-05-31', '2026-11-02')).toBe(5);
    });

    it('reads an unmoved maturity as its calendar months', () => {
        expect(termMonths('2026-09-01', '2027-09-01')).toBe(12);
    });

    it('falls back to the calendar months for a maturity no term produces', () => {
        expect(termMonths('2026-09-01', '2027-09-15')).toBe(12);
    });
});
