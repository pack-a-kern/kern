import type { Span } from '../types/investments';

/** A stretch of time the server counted, in the reader's language — "în 3 ani", "in 5 months". */
export function spanFromNow(span: Span, locale: string): string {
    return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(span.value, span.unit);
}
