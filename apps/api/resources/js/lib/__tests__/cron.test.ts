import { describe, expect, it } from 'vitest';
import { describeCron } from '../cron';
import { en } from '../../i18n/en';
import { ro } from '../../i18n/ro';
import type { Dictionary } from '../../i18n/dictionary';

const t =
    (dictionary: Dictionary) =>
    (pick: (d: Dictionary) => string): string =>
        pick(dictionary);

describe('describeCron', () => {
    it('reads the schedules this installation ships with', () => {
        const say = t(en);

        // Every expression in config/operations.php, so the screen never falls back for its own
        // defaults — the fallback exists for what an operator writes, not for what we wrote.
        expect(describeCron('0 14 * * 1-5', say)).toBe('Weekdays at 14:00');
        expect(describeCron('15 0 * * *', say)).toBe('Every day at 00:15');
        expect(describeCron('30 5 2 * *', say)).toBe('Monthly on day 2, at 05:30');
        expect(describeCron('0 3 * * 0', say)).toBe('Sundays at 03:00');
        expect(describeCron('*/10 * * * *', say)).toBe('Every 10 minutes');
    });

    it('speaks whichever language the screen is in', () => {
        expect(describeCron('0 14 * * 1-5', t(ro))).toBe('În zilele lucrătoare la 14:00');
        expect(describeCron('0 3 * * 0', t(ro))).toBe('Duminica la 03:00');
    });

    it('counts days from Sunday, as cron does', () => {
        const say = t(en);

        expect(describeCron('0 9 * * 1', say)).toBe('Mondays at 09:00');
        expect(describeCron('0 9 * * 6', say)).toBe('Saturdays at 09:00');
        // 0 and 7 are both Sunday.
        expect(describeCron('0 9 * * 7', say)).toBe('Sundays at 09:00');
    });

    it('says nothing rather than something wrong', () => {
        const say = t(en);

        // A day-of-month and a day-of-week together are OR'd by cron, not AND'd: "the 1st, and also
        // every Monday". Reading either half alone would state a schedule that is not the one set.
        expect(describeCron('0 9 1 * 1', say)).toBeNull();
        // Anything naming a month.
        expect(describeCron('0 9 1 3 *', say)).toBeNull();
        // Lists and ranges this does not take apart.
        expect(describeCron('0 9,17 * * *', say)).toBeNull();
        expect(describeCron('0 9-17 * * *', say)).toBeNull();
        // No schedule at all — a manual-only task carries none.
        expect(describeCron(null, say)).toBeNull();
        // Not five fields at all.
        expect(describeCron('0 9 * *', say)).toBeNull();
        expect(describeCron('', say)).toBeNull();
    });
});
