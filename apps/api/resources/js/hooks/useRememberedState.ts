import { useCallback, useState } from 'react';

import { usePaging } from '../lib/sharedProps';
import { useActiveSubjectId } from './useActiveSubjectId';

/**
 * Remembering where a person left a screen — its tab, its page, its filters — across a refresh and a
 * visit to another screen.
 *
 * ## One hook, not a component
 *
 * The tabs, the selects and the pager are already shared components, and none of them owns its
 * value: each screen holds it and hands it down. So the memory belongs where the value lives, and
 * this is a drop-in for `useState` — one line per remembered value, with every rule about storing it
 * in this file rather than repeated on ten screens.
 *
 * ## Where it is kept
 *
 * In `localStorage`, beside the theme, the language and the active taxpayer: a preference about how
 * this browser shows things, never data. It survives closing the browser and does not follow a
 * person to another device. Reading or writing can throw in a locked-down browser, and losing the
 * memory must never break the screen, so every access falls back to the default.
 *
 * ## Per taxpayer
 *
 * A filter on one taxpayer's transactions names that taxpayer's account; applied to another taxpayer
 * it would filter to nothing. Screens showing one taxpayer's data pass `perSubject`, and each
 * taxpayer then has views of their own. Screens about the installation — logs, users — do not.
 *
 * ## What is never trusted
 *
 * A stored value is only used when it has the type of the default and passes `isValid`: a value from
 * an older version of a screen, or a filter option that no longer exists, falls back to the default
 * rather than leaving a select showing nothing.
 */

type Remembered = string | number | boolean;

const PREFIX = 'kern.view';

export interface RememberOptions<T extends Remembered> {
    /** Keep a separate value for each taxpayer — for screens showing one taxpayer's data. */
    perSubject?: boolean;
    /** Whether a stored value is still one the screen can show. */
    isValid?: (value: T) => boolean;
}

/** `perSubject` keys carry the taxpayer they belong to; `none` while no taxpayer is chosen. */
function storageKey(key: string, perSubject: boolean, subjectId: string | null): string {
    return perSubject ? `${PREFIX}.${subjectId ?? 'none'}.${key}` : `${PREFIX}.${key}`;
}

function read<T extends Remembered>(fullKey: string, initial: T, isValid: ((value: T) => boolean) | undefined): T {
    try {
        const raw = window.localStorage.getItem(fullKey);

        if (raw === null) {
            return initial;
        }

        const parsed: unknown = JSON.parse(raw);

        return typeof parsed === typeof initial && (isValid === undefined || isValid(parsed as T))
            ? (parsed as T)
            : initial;
    } catch {
        return initial;
    }
}

function write<T extends Remembered>(fullKey: string, value: T, initial: T): void {
    try {
        // The default is not stored: an empty key and a stored default mean the same thing, and only
        // one of them leaves nothing behind.
        if (value === initial) {
            window.localStorage.removeItem(fullKey);
        } else {
            window.localStorage.setItem(fullKey, JSON.stringify(value));
        }
    } catch {
        // Best-effort convenience only — see the module docblock.
    }
}

export function useRememberedState<T extends Remembered>(
    key: string,
    initial: T,
    options: RememberOptions<T> = {},
): [T, (next: T) => void] {
    // The same taxpayer the screen itself works in, resolved the same way — never the stored id alone,
    // which a new device does not have yet on its first render (`useActiveSubjectId`).
    const subjectId = useActiveSubjectId();
    const fullKey = storageKey(key, options.perSubject ?? false, subjectId);

    // Set values are held per storage key, so switching taxpayer reads that taxpayer's value instead of
    // carrying the previous one across. An untouched key is read from storage on render.
    const [set, setSet] = useState<Record<string, T>>({});
    const value = set[fullKey] ?? read(fullKey, initial, options.isValid);

    const update = useCallback(
        (next: T): void => {
            setSet((current) => ({ ...current, [fullKey]: next }));
            write(fullKey, next, initial);
        },
        [fullKey, initial, setSet],
    );

    return [value, update];
}

export interface RememberedPaging {
    page: number;
    pageSize: number;
    setPage: (page: number) => void;
    /** A different page size starts again from the first page: page 3 of 25 is not page 3 of 100. */
    setPageSize: (size: number) => void;
}

/**
 * A remembered page number and page size, the pair every paged table on a screen holds.
 *
 * A remembered page past the end of a list that has since shrunk is each screen's to settle, the same
 * way it already settles one reached by deleting rows: a table paged in the browser shows its last
 * page, and the server clamps one it pages.
 */
export function useRememberedPaging(key: string, options: { perSubject?: boolean } = {}): RememberedPaging {
    const perSubject = options.perSubject ?? false;

    const [page, setPage] = useRememberedState<number>(`${key}.page`, 1, {
        perSubject,
        isValid: (value) => Number.isInteger(value) && value >= 1,
    });
    const paging = usePaging();
    const [pageSize, rememberPageSize] = useRememberedState<number>(`${key}.pageSize`, paging.default, {
        perSubject,
        isValid: (value) => paging.sizes.includes(value),
    });

    const setPageSize = useCallback(
        (size: number): void => {
            rememberPageSize(size);
            setPage(1);
        },
        [rememberPageSize, setPage],
    );

    return { page, pageSize, setPage, setPageSize };
}
