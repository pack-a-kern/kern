import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { JSX } from 'react';
import { cn } from '@/lib/utils';
import { useTranslation } from '../i18n';
import { formatNumber } from '../lib/format';
import { usePaging } from '../lib/sharedProps';

/**
 * The pager that sits in a card's foot: a range summary, a rows-per-page choice, numbered pages and
 * prev/next.
 *
 * Page numbers rather than only prev/next, because "where am I in this" is a question a person
 * asks of a list they are working through, and a bare pair of arrows cannot answer it. Long runs
 * collapse around the current page so the control stays one row wide however many pages there are.
 */

/**
 * The page numbers to draw, with `null` standing for a gap.
 *
 * Seven slots, always, once there is anything to collapse: first and last are always reachable in
 * one click, and the rest is a run around where you are. A window of the current page ± 1 was
 * narrower near the ends — page 2 of 100 drew four slots and page 50 drew seven — so the control
 * changed width as you paged through it and the numbers moved under the cursor. At the ends the run
 * is spent on the five pages you can actually reach from there instead.
 */
export function pageWindow(current: number, total: number): (number | null)[] {
    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    if (current <= 4) {
        return [1, 2, 3, 4, 5, null, total];
    }

    if (current >= total - 3) {
        return [1, null, total - 4, total - 3, total - 2, total - 1, total];
    }

    return [1, null, current - 1, current, current + 1, null, total];
}

export default function Pagination(props: {
    page: number;
    pageSize: number;
    /** Total rows across every page — not the number on this one. */
    total: number;
    onPageChange: (page: number) => void;
    /**
     * `null` where the page size is not the reader's to choose — a screen that takes it from the
     * server, say.
     *
     * Required rather than optional, and that is the whole point: optional, a screen could omit it
     * by accident and silently lose the control every other listing has. The statement imports
     * screen did exactly that, and nobody had decided it should differ. `null` says somebody did.
     */
    onPageSizeChange: ((pageSize: number) => void) | null;
    /** Names the thing being counted, e.g. "documents". */
    unit: string;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const { sizes } = usePaging();
    const totalPages = Math.max(1, Math.ceil(props.total / props.pageSize));
    const first = props.total === 0 ? 0 : (props.page - 1) * props.pageSize + 1;
    const last = Math.min(props.page * props.pageSize, props.total);
    const format = (value: number): string => formatNumber(value, locale);

    // Nothing to page through, so nothing to say about it. The empty state above already says the
    // list is empty; a foot reading "0–0 of 0" under it says so a second time, and offers a page
    // button for a page that does not exist.
    if (props.total === 0) {
        return <></>;
    }

    return (
        <>
            <div className="text-muted-foreground flex items-center justify-between gap-3 text-2xs sm:justify-start">
                <span className="tabular-nums">
                    {t((d) => d.pagination.range)
                        .replace('{first}', format(first))
                        .replace('{last}', format(last))
                        .replace('{total}', format(props.total))
                        .replace('{unit}', props.unit)}
                </span>

                {/* Shown on a phone too: it was hidden there, which left the one screen where a
                    shorter page helps most unable to ask for one. */}
                {props.onPageSizeChange !== null && (
                    <>
                        <span className="hidden opacity-50 sm:inline">•</span>
                        <label className="flex items-center gap-1.5">
                            <span>{t((d) => d.pagination.rows)}</span>
                            <select
                                value={props.pageSize}
                                onChange={(event) => {
                                    props.onPageSizeChange?.(Number(event.target.value));
                                }}
                                className="border-border bg-card text-muted-foreground h-7 rounded-lg border px-2 text-2xs font-semibold"
                            >
                                {sizes.map((size) => (
                                    <option key={size} value={size}>
                                        {size}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </>
                )}
            </div>

            {/* Only once there is somewhere to go. A single page drew a `1` between two dead arrows
                — navigation for a list that has none — which was quiet enough to live with while it
                was a small button and is not once it is a bar across the screen.

                Two controls, not one made responsive: a phone gets a bar it can be thumbed through,
                a wide screen gets the numbers. Numbered pages squeezed onto 390px were 24px targets
                with no room between them, and the page you wanted was never the one you hit. */}
            {totalPages <= 1 ? null : (
                <nav className="pager-nav" aria-label={t((d) => d.pagination.nav)}>
                    <div className="pager-group pager-mobile">
                        <button
                            type="button"
                            className="pager-step"
                            disabled={props.page <= 1}
                            aria-label={t((d) => d.pagination.previous)}
                            onClick={() => {
                                props.onPageChange(props.page - 1);
                            }}
                        >
                            <ChevronLeft className="size-4" />
                        </button>
                        <span className="pager-where">
                            {t((d) => d.pagination.pageOf)
                                .replace('{page}', format(props.page))
                                .replace('{total}', format(totalPages))}
                        </span>
                        <button
                            type="button"
                            className="pager-step"
                            disabled={props.page >= totalPages}
                            aria-label={t((d) => d.pagination.next)}
                            onClick={() => {
                                props.onPageChange(props.page + 1);
                            }}
                        >
                            <ChevronRight className="size-4" />
                        </button>
                    </div>

                    <div className="pager-group pager-wide">
                        <button
                            type="button"
                            className="pager-step"
                            disabled={props.page <= 1}
                            aria-label={t((d) => d.pagination.previous)}
                            onClick={() => {
                                props.onPageChange(props.page - 1);
                            }}
                        >
                            <ChevronLeft className="size-3.5" />
                        </button>

                        {pageWindow(props.page, totalPages).map((entry, index) =>
                            entry === null ? (
                                <span
                                    key={`gap-${String(index)}`}
                                    className="pager-gap"
                                    aria-label={t((d) => d.pagination.morePages)}
                                >
                                    …
                                </span>
                            ) : (
                                <button
                                    key={entry}
                                    type="button"
                                    aria-current={entry === props.page ? 'page' : undefined}
                                    onClick={() => {
                                        props.onPageChange(entry);
                                    }}
                                    className={cn('pager-page', entry === props.page && 'pager-page-current')}
                                >
                                    {format(entry)}
                                </button>
                            ),
                        )}

                        <button
                            type="button"
                            className="pager-step"
                            disabled={props.page >= totalPages}
                            aria-label={t((d) => d.pagination.next)}
                            onClick={() => {
                                props.onPageChange(props.page + 1);
                            }}
                        >
                            <ChevronRight className="size-3.5" />
                        </button>
                    </div>
                </nav>
            )}
        </>
    );
}
