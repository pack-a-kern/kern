import { useCallback, useSyncExternalStore } from 'react';

/**
 * Whether a CSS media query currently matches, kept in sync as it changes.
 *
 * General on purpose: this is the one place that talks to `matchMedia` for layout decisions, so a
 * component asks a question about the device rather than reimplementing the listener plumbing —
 * and every caller stays correct when a window is resized or a tablet is rotated.
 *
 * Guards `window` so it is safe under any non-browser render, returning `false` — the conservative
 * answer, since every caller uses a match to *add* something.
 */
export function useMediaQuery(query: string): boolean {
    const subscribe = useCallback(
        (onStoreChange: () => void) => {
            if (typeof window === 'undefined') {
                return () => {
                    // Nothing to unsubscribe from; `getSnapshot` already reports the safe default.
                };
            }

            const media = window.matchMedia(query);

            media.addEventListener('change', onStoreChange);

            return () => {
                media.removeEventListener('change', onStoreChange);
            };
        },
        [query],
    );

    const getSnapshot = useCallback(() => typeof window !== 'undefined' && window.matchMedia(query).matches, [query]);

    // `matchMedia` is external state that changes without React's involvement, which is exactly
    // what this hook is for — an effect mirroring it into `useState` would re-render a step behind
    // and re-run on every query change.
    return useSyncExternalStore(subscribe, getSnapshot, () => false);
}

/**
 * Whether taking a photo is a thing this device can actually do.
 *
 * `capture="environment"` is honoured only on mobile; a desktop browser ignores it entirely and
 * opens an ordinary file picker, so a "Take photo" button there is a second "Choose files" button
 * wearing a camera icon. `pointer: coarse` is the honest test — it asks whether the primary input
 * is a finger, which is true of exactly the devices where the camera shortcut means something.
 */
export function useCanTakePhoto(): boolean {
    return useMediaQuery('(pointer: coarse)');
}
