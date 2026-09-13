import { useCallback, useState } from 'react';

import { fieldErrors, isValidationError } from '../lib/apiErrors';

/**
 * Server-side validation errors, held per field.
 *
 * Every form in this application submits to an endpoint that answers a 422 by naming the fields it
 * rejected. Reporting that only as a toast — worse, as "an unexpected error occurred" — leaves the
 * person to guess which of eight inputs the server meant. This keeps the messages where the fields
 * can show them.
 *
 * The API names fields in snake_case and forms hold them in camelCase, so lookup accepts either.
 */
export interface FormErrors {
    /** The message for one field, in whichever case the caller uses. */
    get: (field: string) => string | undefined;
    /** Records the errors from a failed request. Returns true when it was a validation failure. */
    capture: (cause: unknown) => boolean;
    /** Clears one field, for when it is edited — a stale error reads as a rejected correction. */
    clear: (field: string) => void;
    /**
     * Puts a message on one field without a request having failed.
     *
     * For a rejection the form learns about outside a submit — a CNP checked on its own, before
     * the rest of the form is even shown. The message still belongs on the field rather than in a
     * toast, which is the whole point of this hook.
     */
    set: (field: string, message: string) => void;
    reset: () => void;
    any: boolean;
}

function snakeCase(field: string): string {
    return field.replace(/[A-Z]/g, (letter) => `_${letter.toLowerCase()}`);
}

export function useFormErrors(): FormErrors {
    const [errors, setErrors] = useState<Record<string, string>>({});

    const get = useCallback((field: string): string | undefined => errors[snakeCase(field)] ?? errors[field], [errors]);

    const capture = useCallback((cause: unknown): boolean => {
        if (!isValidationError(cause)) {
            return false;
        }

        setErrors(fieldErrors(cause));

        return true;
    }, []);

    const clear = useCallback((field: string): void => {
        // Rebuilt rather than `delete`d: a dynamic delete is disallowed by the lint rules, and
        // filtering states the intent — keep everything that is not this field.
        setErrors((current) =>
            Object.fromEntries(Object.entries(current).filter(([key]) => key !== snakeCase(field) && key !== field)),
        );
    }, []);

    const set = useCallback((field: string, message: string): void => {
        setErrors((current) => ({ ...current, [snakeCase(field)]: message }));
    }, []);

    const reset = useCallback((): void => {
        setErrors({});
    }, []);

    return { get, capture, clear, set, reset, any: Object.keys(errors).length > 0 };
}
