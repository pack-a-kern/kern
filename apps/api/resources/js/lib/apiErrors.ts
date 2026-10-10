import { toast } from 'sonner';

import { ApiError, StepUpCancelled } from './http';
import type { Dictionary } from '../i18n/dictionary';

/** How a screen reads its own dictionary. Matches `useTranslation()`'s `t`. */
type Translate = <T>(pick: (d: Dictionary) => T) => T;

/**
 * The per-field messages from a Laravel validation failure, one per field.
 *
 * A 422 names the field that was rejected. Reporting it only as a toast — "an unexpected error
 * occurred", or even the message itself — leaves the person to work out which of eight inputs the
 * server meant. The field knows, so the field should say so.
 *
 * Returns the first message per field: Laravel can return several, but a form shows one line under
 * a control, and the first is the one that failed first.
 */
export function fieldErrors(cause: unknown): Record<string, string> {
    if (!(cause instanceof ApiError) || cause.errors === undefined) {
        return {};
    }

    const errors: Record<string, string> = {};

    for (const [field, messages] of Object.entries(cause.errors)) {
        const first = messages[0];

        if (first !== undefined) {
            errors[field] = first;
        }
    }

    return errors;
}

/** True when the failure is a field-level rejection the form can display in place. */
export function isValidationError(cause: unknown): boolean {
    return cause instanceof ApiError && cause.status === 422 && Object.keys(fieldErrors(cause)).length > 0;
}

/**
 * One sentence a person can act on, for any failed request.
 *
 * There were six copies of this, one per screen, and they disagreed: some showed the server's own
 * `message`, some showed only "an unexpected error occurred". So a real answer — "a PF profile with
 * this CNP is already registered" — reached the screen on one page and was thrown away on the next.
 * The API has one error shape; this is its one reading.
 *
 * The order is deliberate, most specific first:
 *
 * 1. a transport failure, which is about the network and not about the request;
 * 2. rate limiting, which says *wait*, not *fix something*;
 * 3. a field-level rejection, the most precise thing a 422 can carry;
 * 4. the server's own `message` — a domain refusal like a duplicate CNP explains itself;
 * 5. a generic line, only when the server sent nothing worth reading.
 *
 * Field errors also belong *on the field* — see {@link fieldErrors}. This is for the toast that
 * accompanies them, and for the failures no field owns.
 */
export function describeApiFailure(cause: unknown, t: Translate): string {
    if (!(cause instanceof ApiError)) {
        return t((d) => d.errors.unexpected);
    }

    if (cause.reason === 'network') {
        return t((d) => d.errors.network);
    }

    if (cause.isRateLimited) {
        return t((d) => d.errors.rateLimited);
    }

    const field = Object.values(fieldErrors(cause))[0];

    if (field !== undefined) {
        return field;
    }

    // A named refusal, in the reader's language. The server's `message` says the same thing in
    // English for whoever reads a log; `reason` is the half that can be translated.
    const named = cause.reason === undefined ? undefined : t((d) => d.errors.reasons)[cause.reason];

    if (named !== undefined) {
        return named;
    }

    return cause.message.trim() === '' ? t((d) => d.errors.unexpected) : cause.message;
}

/**
 * Reports a failed request to the person, and says nothing when there was no failure.
 *
 * ## Why reporting is shared and not left to each screen
 *
 * {@link describeApiFailure} already fixed half of this: the error *message* had been written six
 * ways on six screens and they disagreed. The toast around it was still written at every call site,
 * which left the other half open — nothing shared decided *whether* to speak at all.
 *
 * That is what a dismissed step-up dialog fell through. A screen's `catch` cannot tell "they closed
 * the password box" from "the server refused", because both arrive as a rejected promise, so
 * cancelling "end this session" announced that the session could not be ended. Ten actions behind
 * that gate, ten screens that each had to know. Now one place knows, and a new gated action inherits
 * it by calling this.
 *
 * `title` is the screen's own line — "the session could not be ended" — with the server's reading
 * underneath it. Without one, the server's reading is the whole toast: right where the action has no
 * name worth repeating back.
 */
export function reportApiFailure(cause: unknown, t: Translate, title?: string): void {
    // Nothing failed: the request was refused pending a confirmation, and the confirmation was
    // declined. The person who closed the dialog knows what they did.
    if (cause instanceof StepUpCancelled) {
        return;
    }

    const description = describeApiFailure(cause, t);

    if (title === undefined) {
        toast.error(description);

        return;
    }

    toast.error(title, { description });
}
