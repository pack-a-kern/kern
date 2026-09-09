import { ApiError } from './http';

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
