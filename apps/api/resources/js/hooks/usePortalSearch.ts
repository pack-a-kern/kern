import { useCallback, useEffect, useRef, useState } from 'react';

import { cancelPortalSearch, getPortalSearch, startPortalSearch } from '../api/legislationApi';
import { subscribeToPortalSearches } from '../lib/echo';
import { useSharedAuth } from '../lib/sharedProps';
import type { PortalSearchState } from '../types/legislation';

type PortalQuery = Parameters<typeof startPortalSearch>[0];

/**
 * A search of legislatie.just.ro, started on the server and read there a page at a time (ADR 0024), as
 * this screen follows it.
 *
 * While it runs the search is asked for again when the person's own channel says it moved, and on the
 * interval the server gives (`pollAfterMs`) when nothing came — the socket may be down, or a message
 * lost; the answer is the same either way. Never two requests at once: a message arriving while one is
 * in flight is answered by one more request after it, not a second beside it. Everything is released
 * when the search finishes, another starts, or the screen goes: the listener, the timer, and any request
 * still in flight.
 */
export function usePortalSearch(): {
    search: PortalSearchState | null;
    start: (query: PortalQuery, type: string | null) => Promise<void>;
    cancel: () => Promise<void>;
    clear: () => void;
    /** Asks for the search again — once an act it found has been read in elsewhere. */
    reload: () => Promise<void>;
} {
    const userId = useSharedAuth()?.user.id ?? null;
    const [search, setSearch] = useState<PortalSearchState | null>(null);
    const latest = useRef(search);
    const runningId = search?.status === 'running' ? search.id : null;

    useEffect(() => {
        latest.current = search;
    }, [search]);

    useEffect(() => {
        if (runningId === null) {
            return;
        }

        const id = runningId;
        const abort = new AbortController();
        let timer: ReturnType<typeof setTimeout> | null = null;
        let inFlight = false;
        let again = false;

        const schedule = (): void => {
            if (timer !== null) {
                clearTimeout(timer);
            }

            const after = latest.current?.pollAfterMs ?? null;
            timer = after === null ? null : setTimeout(() => void refresh(), after);
        };

        const refresh = async (): Promise<void> => {
            if (inFlight) {
                again = true;

                return;
            }

            inFlight = true;

            try {
                const next = await getPortalSearch(id, abort.signal);

                if (!abort.signal.aborted) {
                    latest.current = next;
                    setSearch(next);
                }
            } catch {
                // Aborted on the way out, or the server did not answer this once: the next message or
                // the next interval asks again, and the screen keeps what it last had meanwhile.
            } finally {
                inFlight = false;
            }

            if (abort.signal.aborted) {
                return;
            }

            if (again) {
                again = false;
                void refresh();
            } else {
                schedule();
            }
        };

        const unsubscribe =
            userId === null
                ? () => undefined
                : subscribeToPortalSearches(userId, (changed) => {
                      if (changed === id) {
                          void refresh();
                      }
                  });

        schedule();

        return () => {
            abort.abort();
            unsubscribe();

            if (timer !== null) {
                clearTimeout(timer);
            }
        };
    }, [runningId, userId]);

    const start = useCallback(async (query: PortalQuery, type: string | null): Promise<void> => {
        setSearch(await startPortalSearch(query, type));
    }, []);

    const cancel = useCallback(async (): Promise<void> => {
        const current = latest.current;

        if (current?.status === 'running') {
            setSearch(await cancelPortalSearch(current.id));
        }
    }, []);

    const clear = useCallback((): void => {
        setSearch(null);
    }, []);

    const reload = useCallback(async (): Promise<void> => {
        const current = latest.current;

        if (current !== null) {
            setSearch(await getPortalSearch(current.id));
        }
    }, []);

    return { search, start, cancel, clear, reload };
}
