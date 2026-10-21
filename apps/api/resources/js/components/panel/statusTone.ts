import type { StatusTone } from './index';

/**
 * A status tone as a text colour, for the dot-and-label form the document tables use.
 *
 * One copy, so the inbox and the review screen describe a document's state identically.
 */
export function statusToneClass(tone: StatusTone): string {
    return {
        ok: 'text-success',
        warn: 'text-warning',
        bad: 'text-destructive',
        idle: 'text-muted-foreground',
    }[tone];
}
