import { useCallback, useEffect, useSyncExternalStore } from 'react';

export type ThemePreference = 'light' | 'dark' | 'system';
type ResolvedTheme = 'light' | 'dark';

/**
 * App-wide colour-scheme preference. Deliberately hand-written instead of the `next-themes`
 * package shadcn's own `sonner.tsx` template imports by default (`docs/handoff/040-ui-foundation.md`
 * "Key decisions") — `next-themes` is built and named for Next.js's App Router (script injection
 * before hydration to avoid a flash-of-wrong-theme); this app has no server-rendered HTML to
 * flash against (Inertia hands the SPA an already-rendered Blade shell with no theme opinion), and
 * `docs/DEPENDENCY-POLICY.md` §4 orders "the standard library" and "an existing dependency" above
 * "a well-maintained third-party package." This hook is the one thing in the app that reads/writes
 * `localStorage['kern.theme']` and toggles the `.dark` class `app.css`'s `@custom-variant dark`
 * selector matches — a non-sensitive UI preference, not fiscal data (`BLUEPRINT.md` §17).
 */
const STORAGE_KEY = 'kern.theme';
const listeners = new Set<() => void>();
let preference: ThemePreference = readStoredPreference();

function readStoredPreference(): ThemePreference {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (stored === 'light' || stored === 'dark' || stored === 'system') {
            return stored;
        }
    } catch {
        // Private browsing / storage disabled — fall back to system preference every time.
    }

    return 'system';
}

function prefersDark(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function resolve(pref: ThemePreference): ResolvedTheme {
    return pref === 'system' ? (prefersDark() ? 'dark' : 'light') : pref;
}

function applyToDocument(pref: ThemePreference): void {
    document.documentElement.classList.toggle('dark', resolve(pref) === 'dark');
}

function notify(): void {
    for (const listener of listeners) {
        listener();
    }
}

function setPreference(next: ThemePreference): void {
    preference = next;
    applyToDocument(next);

    try {
        localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // Non-fatal — the in-memory preference for this tab still applies.
    }

    notify();
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getSnapshot(): ThemePreference {
    return preference;
}

// Applied once, eagerly, at module load — before the first paint of any component that uses this
// hook — so there is no flash of the wrong theme on first render.
if (typeof document !== 'undefined') {
    applyToDocument(preference);
}

export function useTheme(): {
    preference: ThemePreference;
    resolved: ResolvedTheme;
    setPreference: (next: ThemePreference) => void;
} {
    const current = useSyncExternalStore(subscribe, getSnapshot, (): ThemePreference => 'system');

    useEffect(() => {
        if (current !== 'system') {
            return;
        }

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => {
            applyToDocument('system');
            notify();
        };

        media.addEventListener('change', onChange);

        return () => {
            media.removeEventListener('change', onChange);
        };
    }, [current]);

    return {
        preference: current,
        resolved: resolve(current),
        setPreference: useCallback((next: ThemePreference) => {
            setPreference(next);
        }, []),
    };
}
