import { useSyncExternalStore } from 'react';
import { DEFAULT_PALETTE, isPaletteName, paletteVariables, type PaletteName } from '../lib/palette';

/**
 * The interface palette, applied as CSS custom properties on the document root.
 *
 * Deliberately the same shape and mechanism as {@link import('./useTheme').useTheme}: one module
 * owning one `localStorage` key, a `useSyncExternalStore` subscription so every component agrees,
 * and the value applied once at module load so nothing flashes. Theme answers "light or dark",
 * this answers "which brand" — two independent axes that compose, which is why they are two hooks
 * and not one.
 *
 * It writes variables rather than swapping a stylesheet or a class per palette, so `app.css` styles
 * against `--brand-*` once and every palette — including ones added later — works without a single
 * new rule.
 *
 * A palette is a display preference on this device, not fiscal data (`BLUEPRINT.md` §17), so
 * `localStorage` is the right home for it. It does not follow a person to another browser; the
 * deployment-wide starting point is `VITE_DEFAULT_PALETTE`.
 */
const STORAGE_KEY = 'taxos.palette';
const listeners = new Set<() => void>();
let current: PaletteName = readStoredPalette();

function readStoredPalette(): PaletteName {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (isPaletteName(stored)) {
            return stored;
        }
    } catch {
        // Private browsing / storage disabled — the configured default applies every time.
    }

    return DEFAULT_PALETTE;
}

function applyToDocument(name: PaletteName): void {
    const root = document.documentElement;

    for (const [property, value] of Object.entries(paletteVariables(name))) {
        root.style.setProperty(property, value);
    }
}

function notify(): void {
    for (const listener of listeners) {
        listener();
    }
}

function setPalette(next: PaletteName): void {
    current = next;
    applyToDocument(next);

    try {
        localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // Non-fatal — the in-memory choice still applies for this tab.
    }

    notify();
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getSnapshot(): PaletteName {
    return current;
}

// Applied eagerly at module load, before the first paint of any component that uses this hook.
// `app.css` carries the default palette's ramp as literal fallbacks, so the gap before this runs
// shows the default rather than an unstyled page.
if (typeof document !== 'undefined') {
    applyToDocument(current);
}

export function usePalette(): {
    palette: PaletteName;
    setPalette: (next: PaletteName) => void;
} {
    return {
        palette: useSyncExternalStore(subscribe, getSnapshot, (): PaletteName => DEFAULT_PALETTE),
        setPalette,
    };
}
