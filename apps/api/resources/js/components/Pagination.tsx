import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { JSX } from 'react';
import { cn } from '@/lib/utils';
import { useTranslation } from '../i18n';

/**
 * The pager that sits in a card's foot: a range summary, a rows-per-page choice, numbered pages and
 * prev/next.
 *
 * Page numbers rather than only prev/next, because "where am I in this" is a question a person
 * asks of a list they are working through, and a bare pair of arrows cannot answer it. Long runs
 * collapse around the current page so the control stays one row wide however many pages there are.
 */

/** How many rows a page may hold. Kept here so every list offers the same choices. */
export const PAGE_SIZES = [10, 25, 50, 100] as const;

/**
 * The page numbers to draw, with `null` standing for a gap.
 *
 * Always first and last, plus a window around the current page — so the ends stay reachable in one
 * click and the control never grows past seven slots. Below eight pages there is nothing to
 * collapse and every number is shown.
 */
export function pageWindow(current: number, total: number): (number | null)[] {
    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const from = Math.max(2, current - 1);
    const to = Math.min(total - 1, current + 1);
    const pages: (number | null)[] = [1];

    if (from > 2) {
        pages.push(null);
    }

    for (let page = from; page <= to; page += 1) {
        pages.push(page);
    }

    if (to < total - 1) {
        pages.push(null);
    }

    pages.push(total);

    return pages;
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
    const totalPages = Math.max(1, Math.ceil(props.total / props.pageSize));
    const first = props.total === 0 ? 0 : (props.page - 1) * props.pageSize + 1;
    const last = Math.min(props.page * props.pageSize, props.total);
    const format = (value: number): string => value.toLocaleString(locale);

    // Nothing to page through, so nothing to say about it. The empty state above already says the
    // list is empty; a foot reading "0–0 of 0" under it says so a second time, and offers a page
    // button for a page that does not exist.
    if (props.total === 0) {
        return <></>;
    }

    return (
        <>
            <div className="text-muted-foreground flex items-center justify-between gap-3 text-[10px] sm:justify-start">
                <span className="tabular-nums">
                    {t((d) => d.pagination.range)
                        .replace('{first}', format(first))
                        .replace('{last}', format(last))
                        .replace('{total}', format(props.total))
                        .replace('{unit}', props.unit)}
                </span>

                {props.onPageSizeChange !== null && (
                    <>
                        <span className="hidden opacity-50 sm:inline">•</span>
                        <label className="hidden items-center gap-1.5 sm:flex">
                            <span>{t((d) => d.pagination.rows)}</span>
                            <select
                                value={props.pageSize}
                                onChange={(event) => {
                                    props.onPageSizeChange?.(Number(event.target.value));
                                }}
                                className="border-border bg-card text-muted-foreground h-7 rounded-lg border px-2 text-[10px] font-semibold"
                            >
                                {PAGE_SIZES.map((size) => (
                                    <option key={size} value={size}>
                                        {size}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </>
                )}
            </div>

            <div className="flex items-center justify-between gap-2 sm:justify-end">
                <div className="flex items-center gap-1">
                    {pageWindow(props.page, totalPages).map((entry, index) =>
                        entry === null ? (
                            <span
                                key={`gap-${String(index)}`}
                                className="text-muted-foreground grid size-8 place-items-center text-[10px]"
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
                </div>

                <div className="border-border bg-card inline-flex overflow-hidden rounded-xl border">
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
                    <div className="bg-border w-px" />
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
            </div>
        </>
    );
}
