import { useCallback, useEffect, useRef } from 'react';

/**
 * Collapses a burst of calls into one, run a short moment after the last of them.
 *
 * Reverb events do not arrive one at a time. Uploading five documents produces five
 * `DocumentUploaded` events within a few hundred milliseconds, then five `DocumentScanned`, and so
 * on through the pipeline — and a screen that reacts to each one individually issues twenty
 * requests to learn what one request would have told it.
 *
 * Trailing rather than leading: the point is to answer the *last* event of a burst, since that is
 * the one whose state is current. A leading call would fetch a list that the four events behind it
 * are about to invalidate.
 *
 * The callback is held in a ref, so a caller may pass an inline function without restarting the
 * timer on every render.
 */
export function useCoalescedCallback(callback: () => void, delayMs = 400): () => void {
    const callbackRef = useRef(callback);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Assigned in an effect rather than during render: a ref write during render is a side effect
    // in what must be a pure function, and React may render a component more than once per commit.
    useEffect(() => {
        callbackRef.current = callback;
    }, [callback]);

    useEffect(
        () => () => {
            if (timerRef.current !== null) {
                clearTimeout(timerRef.current);
            }
        },
        [],
    );

    return useCallback(() => {
        if (timerRef.current !== null) {
            clearTimeout(timerRef.current);
        }

        timerRef.current = setTimeout(() => {
            timerRef.current = null;
            callbackRef.current();
        }, delayMs);
    }, [delayMs]);
}
