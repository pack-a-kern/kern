import type { Dictionary } from '../i18n/dictionary';

type Words = Dictionary['pages']['legislation']['watches'];

/** A law watch's interval in the reader's words: "Every day", "Every 7 days", "Every 6 hours". */
export function intervalLabel(words: Words, hours: number): string {
    if (hours === 24) {
        return words.everyDay;
    }

    return hours % 24 === 0
        ? words.everyDays.replace('{days}', String(hours / 24))
        : words.everyHours.replace('{hours}', String(hours));
}
