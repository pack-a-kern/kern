import { useMemo, useState } from 'react';

/**
 * Rows ticked for a bulk action, and the two questions a table asks about them.
 *
 * ## Why the selection outlives the page
 *
 * Ids are kept across pages and filter changes on purpose: ticking ten rows, paging to find an
 * eleventh and losing the first ten is the behaviour people expect least. It is cleared only when
 * the action they were selected for completes — which is why {@link RowSelection.clear} exists and
 * nothing else resets it.
 *
 * ## Why select-all is per page
 *
 * `toggleAll` ticks what is on screen and nothing else. A control that silently selects rows nobody
 * has seen — the other four hundred behind a filter — turns "delete selected" into a question the
 * person cannot have answered.
 */
export interface RowSelection {
    /** The ticked ids, across every page visited. */
    ids: ReadonlySet<string>;
    size: number;
    has: (id: string) => boolean;
    toggle: (id: string, next: boolean) => void;
    /** Ticks or unticks every row currently on screen. */
    toggleAll: (next: boolean) => void;
    /** True when every row on this page is ticked — the header checkbox's state. */
    allOnPage: boolean;
    /** True when some but not all are — the header checkbox's indeterminate state. */
    someOnPage: boolean;
    clear: () => void;
}

/**
 * @param pageIds the ids currently rendered, which is what select-all acts on
 */
export function useRowSelection(pageIds: readonly string[]): RowSelection {
    const [ids, setIds] = useState<ReadonlySet<string>>(new Set());

    return useMemo(() => {
        const selectedOnPage = pageIds.filter((id) => ids.has(id)).length;

        const apply = (targets: readonly string[], next: boolean): void => {
            setIds((current) => {
                const updated = new Set(current);

                for (const id of targets) {
                    if (next) {
                        updated.add(id);
                    } else {
                        updated.delete(id);
                    }
                }

                return updated;
            });
        };

        return {
            ids,
            size: ids.size,
            has: (id: string) => ids.has(id),
            toggle: (id: string, next: boolean) => {
                apply([id], next);
            },
            toggleAll: (next: boolean) => {
                apply(pageIds, next);
            },
            allOnPage: pageIds.length > 0 && selectedOnPage === pageIds.length,
            someOnPage: selectedOnPage > 0 && selectedOnPage < pageIds.length,
            clear: () => {
                setIds(new Set());
            },
        };
    }, [ids, pageIds]);
}
