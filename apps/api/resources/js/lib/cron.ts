import type { Dictionary } from '../i18n/dictionary';

/**
 * A cron expression, said in words.
 *
 * `0 14 * * 1-5` is precise, stable and completely opaque to the person deciding whether a task runs
 * often enough. The expression itself stays on the screen — it is what the operator would change,
 * and the reading is a gloss on it rather than a replacement.
 *
 * Deliberately not a general cron parser. These expressions come from `config/operations.php`, where
 * each is one `env()` away from being anything, so the shapes below are the ones that occur plus the
 * ones a person is likely to write; everything else returns `null` and the caller shows the raw
 * expression alone. A wrong reading of a schedule would be worse than no reading: somebody would
 * believe it.
 *
 * Written here rather than taken from a package because the shapes are few, the fallback is honest,
 * and the wording has to come from the same dictionary as the rest of the screen — a library would
 * bring its own locale data and its own idea of how Romanian reads.
 */

/** A field that means "every value", so it adds nothing to the sentence. */
function isAny(field: string): boolean {
    return field === '*';
}

// A step field such as "every 15" yields 15; anything else yields null.
function stepOf(field: string): number | null {
    const match = /^\*\/([0-9]+)$/.exec(field);

    if (match === null) {
        return null;
    }

    const step = Number(match[1]);

    return Number.isInteger(step) && step > 1 ? step : null;
}

/** A single number, or null for anything else — a list, a range, a step. */
function exactly(field: string): number | null {
    return /^[0-9]+$/.test(field) ? Number(field) : null;
}

/** `14`, `5` → `14:05`. The clock a person reads, not the fields cron stores. */
function atTime(hour: number, minute: number): string {
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

/**
 * The reading, or `null` where there is not one this is sure of.
 *
 * `t` is passed rather than imported so this stays a pure function of its inputs, which is what lets
 * it be tested against both locales without a React tree.
 */
export function describeCron(expression: string | null, t: (pick: (d: Dictionary) => string) => string): string | null {
    if (expression === null) {
        return null;
    }

    const fields = expression.trim().split(/\s+/);

    if (fields.length !== 5) {
        return null;
    }

    const [minute, hour, dayOfMonth, month, dayOfWeek] = fields as [string, string, string, string, string];

    // Anything naming a month is beyond what this reads; a yearly schedule said wrongly is worse
    // than a cron expression said plainly.
    if (!isAny(month)) {
        return null;
    }

    const minuteStep = stepOf(minute);

    // `*/10 * * * *` — the only shape where the hour does not matter.
    if (minuteStep !== null && isAny(hour) && isAny(dayOfMonth) && isAny(dayOfWeek)) {
        return t((d) => d.cron.everyMinutes).replace('{minutes}', String(minuteStep));
    }

    const hourStep = stepOf(hour);
    const exactMinute = exactly(minute);

    if (hourStep !== null && exactMinute !== null && isAny(dayOfMonth) && isAny(dayOfWeek)) {
        return t((d) => d.cron.everyHours)
            .replace('{hours}', String(hourStep))
            .replace('{minute}', String(exactMinute).padStart(2, '0'));
    }

    const exactHour = exactly(hour);

    // Everything below is "at a fixed time, on some set of days".
    if (exactHour === null || exactMinute === null) {
        return null;
    }

    const time = atTime(exactHour, exactMinute);

    if (isAny(dayOfWeek)) {
        if (isAny(dayOfMonth)) {
            return t((d) => d.cron.daily).replace('{time}', time);
        }

        const day = exactly(dayOfMonth);

        return day === null
            ? null
            : t((d) => d.cron.monthly)
                  .replace('{day}', String(day))
                  .replace('{time}', time);
    }

    // A weekday rule and a day-of-month rule together is a cron subtlety — the two are OR'd, not
    // AND'd — and saying either half alone would be a lie.
    if (!isAny(dayOfMonth)) {
        return null;
    }

    if (dayOfWeek === '1-5') {
        return t((d) => d.cron.weekdays).replace('{time}', time);
    }

    // A pair of days that has its own word, however it was written.
    if (['0,6', '6,0', '6-7', '6,7'].includes(dayOfWeek)) {
        return t((d) => d.cron.weekends).replace('{time}', time);
    }

    const weekday = exactly(dayOfWeek);

    // 0 and 7 are both Sunday in cron.
    if (weekday === null || weekday > 7) {
        return null;
    }

    const names = t((d) => d.cron.days);

    return t((d) => d.cron.weekly)
        .replace('{day}', names.split('|')[weekday === 7 ? 0 : weekday] ?? '')
        .replace('{time}', time);
}
