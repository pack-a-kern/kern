import { useEffect } from 'react';

/** How long after the page opens a field taking focus is the device's doing rather than the person's. */
const SETTLING_MS = 1500;

/**
 * Keeps a page from opening with a field focused, and the keyboard up, on a touch screen.
 *
 * Nothing here focuses a field on its own any more. iOS still does on some loads — an installed
 * web app opening on the sign-in page, a password manager offering what it has — and the keyboard
 * then covers the page before anyone touched it. A field focused in the first moments, before any
 * touch, a click or a key, is let go; one the person reaches for keeps its focus as always.
 *
 * Touch screens only: on a desktop a focused field raises nothing, and a keyboard user may well be
 * on their way to it.
 */
export function useNoFocusOnLoad(): void {
    useEffect(() => {
        if (!window.matchMedia('(pointer: coarse)').matches) {
            return;
        }

        let touched = false;
        const reached = (): void => {
            touched = true;
        };
        const release = (): void => {
            const focused = document.activeElement;

            if (!touched && focused instanceof HTMLElement && focused.matches('input, textarea, select')) {
                focused.blur();
            }
        };

        document.addEventListener('pointerdown', reached, true);
        document.addEventListener('keydown', reached, true);
        document.addEventListener('focusin', release, true);
        // A field focused before this ran is let go too.
        release();
        const settled = window.setTimeout(() => {
            document.removeEventListener('focusin', release, true);
        }, SETTLING_MS);

        return () => {
            window.clearTimeout(settled);
            document.removeEventListener('pointerdown', reached, true);
            document.removeEventListener('keydown', reached, true);
            document.removeEventListener('focusin', release, true);
        };
    }, []);
}
