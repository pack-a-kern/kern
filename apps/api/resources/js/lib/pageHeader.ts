import { useSyncExternalStore } from 'react';

/**
 * What the topbar says: the title and one-line description of whatever screen is open.
 *
 * The shell renders it, but only the page knows it — and the page is rendered *inside* the shell,
 * so the value has to travel upwards. A tiny external store does that, the same mechanism
 * `useTheme` and `usePalette` already use here, rather than threading a prop through every screen
 * or lifting page state into the router.
 *
 * The alternative was to have each page pass `title` to `AppShell` itself. That works and needs no
 * store, but it puts the same two strings in two places on every screen and lets them drift; this
 * way `Page` stays the one thing a screen declares its identity to.
 */
export interface PageHeader {
    title: string;
    description?: string | undefined;
}

const listeners = new Set<() => void>();
let current: PageHeader | null = null;

export function setPageHeader(next: PageHeader | null): void {
    // A page re-rendering with identical strings must not notify, or the shell re-renders on every
    // keystroke in any form on the page.
    if (current?.title === next?.title && current?.description === next?.description) {
        return;
    }

    current = next;

    for (const listener of listeners) {
        listener();
    }
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getSnapshot(): PageHeader | null {
    return current;
}

export function usePageHeader(): PageHeader | null {
    return useSyncExternalStore(subscribe, getSnapshot, (): PageHeader | null => null);
}
