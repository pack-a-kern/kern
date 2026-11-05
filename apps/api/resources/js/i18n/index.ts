import { useCallback, useSyncExternalStore } from 'react';
import type { Dictionary } from './dictionary';
import { ro } from './ro';
import { en } from './en';

export type Locale = 'ro' | 'en';

const DICTIONARIES: Record<Locale, Dictionary> = { ro, en };
const STORAGE_KEY = 'kern.locale';
const DEFAULT_LOCALE: Locale = 'ro';

const listeners = new Set<() => void>();
let locale: Locale = readStoredLocale();

function readStoredLocale(): Locale {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (stored === 'ro' || stored === 'en') {
            return stored;
        }
    } catch {
        // Private browsing / storage disabled — Romanian is the product's default audience.
    }

    return DEFAULT_LOCALE;
}

function notify(): void {
    for (const listener of listeners) {
        listener();
    }
}

function setLocale(next: Locale): void {
    locale = next;

    try {
        localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // Non-fatal — the in-memory choice for this tab still applies.
    }

    notify();
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getSnapshot(): Locale {
    return locale;
}

/**
 * The dictionary for the current locale, outside a render — for text produced in a callback that
 * outlives the render it was set up in (a toast raised by a websocket message).
 */
export function getDictionary(): Dictionary {
    return DICTIONARIES[locale];
}

/** The current locale outside a render, for the same callbacks `getDictionary` is for. */
export function getLocale(): Locale {
    return locale;
}

/**
 * Minimal, hand-rolled i18n (`docs/handoff/040-ui-foundation.md` "i18n" explains why this was
 * chosen over `react-i18next`/`next-intl`, per `docs/DEPENDENCY-POLICY.md` §4's "standard library
 * before a third-party package"). `t()` takes a *selector*, not a string key — `ro.ts`/`en.ts`
 * both `satisfies Dictionary`, so a translation missing from either file is a `tsc` error, not a
 * blank string a user can actually see in production. The current choice is a non-sensitive UI
 * preference persisted in `localStorage['kern.locale']` (`BLUEPRINT.md` §17).
 */
export function useTranslation(): {
    t: <T>(select: (dictionary: Dictionary) => T) => T;
    locale: Locale;
    setLocale: (next: Locale) => void;
} {
    const current = useSyncExternalStore(subscribe, getSnapshot, (): Locale => DEFAULT_LOCALE);
    const dictionary = DICTIONARIES[current];

    return {
        locale: current,
        t: useCallback(<T>(select: (d: Dictionary) => T): T => select(dictionary), [dictionary]),
        setLocale: useCallback((next: Locale) => {
            setLocale(next);
        }, []),
    };
}
