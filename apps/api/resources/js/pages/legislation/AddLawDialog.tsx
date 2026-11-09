import { CircleStop, Download, Eye, Loader2, Search, SearchX, TriangleAlert, X } from 'lucide-react';
import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { keepLawWatch, listLawWatches, readPortalAct } from '../../api/legislationApi';
import { EmptyState, Field, PanelButton, PanelDialog, PanelInput, PanelSelect, Pill } from '../../components/panel';
import { usePortalSearch } from '../../hooks/usePortalSearch';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { intervalLabel } from '../../lib/watchInterval';
import type { PortalActResult, PortalSearchState } from '../../types/legislation';

type Mode = 'title' | 'number';

/**
 * Finds an act on legislatie.just.ro and starts reading it: by words of its title — "salariului de
 * bază minim brut", for every decision that set the minimum wage — or by its number and year, which
 * lists every act so numbered, of any type, to pick the right one.
 *
 * The search runs on the server a page at a time (ADR 0024): the acts show as each page is read, the
 * search can be stopped, and one the portal would not finish says how far it got. Closing the dialog
 * stops a search still running.
 *
 * Reading runs as the ingest task, so the lock, the audit record and the run history are the ones
 * every task has; this is only where the act is chosen.
 */
export default function AddLawDialog({
    open,
    types,
    years,
    onOpenChange,
    onStarted,
    onWatched,
}: {
    open: boolean;
    /** The act types a law can be read as — `config/operations.php`, as the portal files them. */
    types: string[];
    /** The years an act is looked for in, from `config/legislation.php` to this one. */
    years: { min: number; max: number } | null;
    onOpenChange: (open: boolean) => void;
    /** A read was queued: the list of laws will change once it runs. */
    onStarted: () => void;
    /** The search was kept to be watched: the list of watches has one more. */
    onWatched: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const [mode, setMode] = useState<Mode>('title');
    const [title, setTitle] = useState('');
    const [number, setNumber] = useState('');
    const [year, setYear] = useState('');
    const [type, setType] = useState('');
    /** The act asked about: importing starts only once it is confirmed. */
    const [confirming, setConfirming] = useState<string | null>(null);
    const [starting, setStarting] = useState(false);
    const [started, setStarted] = useState<Set<string>>(new Set());
    const { search: portal, start, cancel, clear } = usePortalSearch();
    /** The intervals offered once "Watch this search" is asked, in hours; null while not asked. */
    const [watchIntervals, setWatchIntervals] = useState<number[] | null>(null);
    const [watchEvery, setWatchEvery] = useState('');
    const watchWords = t((d) => d.pages.legislation.watches);
    const results = portal?.acts ?? null;
    const running = portal?.status === 'running';

    // Newest first: the act looked for is most often this year's or last year's.
    const yearOptions =
        years === null
            ? []
            : Array.from({ length: years.max - years.min + 1 }, (_, index) => String(years.max - index));

    /** A type in the reader's language; the portal's own name for one this screen has no word for. */
    const typeName = (key: string): string => t((d) => d.pages.legislation.add.types)[key] ?? key;

    const canSearch = mode === 'title' ? title.trim().length >= 3 : number !== '' && year !== '';

    const search = async (): Promise<void> => {
        setStarting(true);

        try {
            const yearValue = year === '' ? null : Number(year);

            setConfirming(null);
            await start(
                mode === 'title'
                    ? { title: title.trim(), year: yearValue }
                    : { number: Number(number), year: yearValue ?? 0 },
                type === '' ? null : type,
            );
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        } finally {
            setStarting(false);
        }
    };

    const stop = async (): Promise<void> => {
        try {
            await cancel();
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        }
    };

    // A watch runs the same search again: words of a title and a type, every year from the one chosen (or
    // the library's first). A search by number names one act, which is read, not watched.
    const canWatch = mode === 'title' && title.trim().length >= 3;

    const askToWatch = async (): Promise<void> => {
        try {
            const { intervals } = await listLawWatches();
            setWatchIntervals(intervals);
            setWatchEvery(String(intervals[0] ?? ''));
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        }
    };

    const watch = async (): Promise<void> => {
        try {
            await keepLawWatch({
                searchWords: title.trim(),
                actType: type,
                firstYear: year === '' ? (years?.min ?? new Date().getFullYear()) : Number(year),
                everyHours: Number(watchEvery),
            });
            setWatchIntervals(null);
            toast.success(watchWords.watched);
            onWatched();
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        }
    };

    const read = async (act: PortalActResult): Promise<void> => {
        setConfirming(null);

        try {
            await readPortalAct(act);
            setStarted((current) => new Set(current).add(act.url));
            toast.success(t((d) => d.pages.legislation.add.started));
            onStarted();
        } catch (cause) {
            toast.error(
                cause instanceof ApiError && cause.status === 409
                    ? t((d) => d.pages.legislation.add.busy)
                    : describeApiFailure(cause, t),
            );
        }
    };

    return (
        <PanelDialog
            open={open}
            onOpenChange={(next) => {
                // A search nobody is looking at any more is stopped, not left reading the portal.
                if (!next) {
                    void stop();
                }

                onOpenChange(next);
            }}
            size="lg"
            title={t((d) => d.pages.legislation.add.title)}
            description={t((d) => d.pages.legislation.add.hint)}
            actions={
                <>
                    <PanelButton
                        onClick={() => {
                            void stop();
                            onOpenChange(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.legislation.add.close)}
                    </PanelButton>
                    <PanelButton primary disabled={!canSearch || starting} onClick={() => void search()}>
                        <Search className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.legislation.add.search)}
                    </PanelButton>
                </>
            }
        >
            <form
                className="grid gap-3 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (canSearch && !starting) {
                        void search();
                    }
                }}
            >
                <Field label={t((d) => d.pages.legislation.add.by)} htmlFor="add-law-mode">
                    <PanelSelect
                        id="add-law-mode"
                        value={mode}
                        onValueChange={(value) => {
                            setMode(value === 'number' ? 'number' : 'title');
                            void stop();
                            clear();
                        }}
                        options={[
                            { value: 'title', label: t((d) => d.pages.legislation.add.byTitle) },
                            { value: 'number', label: t((d) => d.pages.legislation.add.byNumber) },
                        ]}
                    />
                </Field>
                {mode === 'title' ? (
                    <Field label={t((d) => d.pages.legislation.add.words)} htmlFor="add-law-title">
                        <PanelInput
                            id="add-law-title"
                            type="search"
                            maxLength={200}
                            placeholder={t((d) => d.pages.legislation.add.wordsPlaceholder)}
                            value={title}
                            onChange={(event) => {
                                setTitle(event.target.value);
                            }}
                        />
                    </Field>
                ) : (
                    <Field label={t((d) => d.pages.legislation.add.number)} htmlFor="add-law-number">
                        <PanelInput
                            id="add-law-number"
                            type="number"
                            min={1}
                            max={99999}
                            value={number}
                            onChange={(event) => {
                                setNumber(event.target.value);
                            }}
                        />
                    </Field>
                )}
                <Field label={t((d) => d.pages.legislation.add.year)} htmlFor="add-law-year">
                    {/* Any year for words of a title; a number is only an act's with its year. */}
                    <PanelSelect
                        id="add-law-year"
                        value={year}
                        onValueChange={setYear}
                        placeholder={t((d) => d.pages.legislation.add.chooseYear)}
                        {...(mode === 'title' ? { emptyLabel: t((d) => d.pages.legislation.add.anyYear) } : {})}
                        options={yearOptions.map((value) => ({ value, label: value }))}
                    />
                </Field>
                <Field label={t((d) => d.pages.legislation.add.type)} htmlFor="add-law-type">
                    <PanelSelect
                        id="add-law-type"
                        value={type}
                        onValueChange={setType}
                        emptyLabel={t((d) => d.pages.legislation.add.allTypes)}
                        options={types.map((key) => ({ value: key, label: typeName(key) }))}
                    />
                </Field>
                {/* Enter in a field searches; the dialog's own button does the same. */}
                <button type="submit" hidden aria-hidden="true" tabIndex={-1} />
            </form>

            {canWatch ? (
                <div className="mt-3 flex flex-wrap items-center justify-end gap-2">
                    {watchIntervals === null ? (
                        <>
                            <span className="note min-w-0 flex-1">
                                {type === '' ? watchWords.watchNeedsType : watchWords.watchHint}
                            </span>
                            <PanelButton disabled={type === ''} onClick={() => void askToWatch()}>
                                <Eye className="size-3.5" aria-hidden="true" />
                                {watchWords.watch}
                            </PanelButton>
                        </>
                    ) : (
                        <>
                            <PanelSelect
                                aria-label={watchWords.interval}
                                value={watchEvery}
                                onValueChange={setWatchEvery}
                                options={watchIntervals.map((hours) => ({
                                    value: String(hours),
                                    label: intervalLabel(watchWords, hours),
                                }))}
                            />
                            <PanelButton
                                onClick={() => {
                                    setWatchIntervals(null);
                                }}
                            >
                                <X className="size-3.5" aria-hidden="true" />
                                {watchWords.cancel}
                            </PanelButton>
                            <PanelButton
                                primary
                                disabled={watchEvery === '' || type === ''}
                                onClick={() => void watch()}
                            >
                                <Eye className="size-3.5" aria-hidden="true" />
                                {watchWords.watch}
                            </PanelButton>
                        </>
                    )}
                </div>
            ) : null}

            <div className="mt-4 space-y-2" aria-live="polite">
                {portal === null ? null : <SearchProgress search={portal} onStop={() => void stop()} />}
                {results === null ? null : results.length === 0 ? (
                    running ? null : (
                        <EmptyState
                            icon={<SearchX className="size-5" />}
                            title={t((d) => d.pages.legislation.add.none)}
                        />
                    )
                ) : (
                    results.map((act) => (
                        <article
                            key={act.url}
                            className="tile border-border flex flex-wrap items-start justify-between gap-2 border p-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-balance">{act.title}</p>
                                <div className="mt-1 flex flex-wrap gap-1.5">
                                    <Pill tone="accent">{act.typeLabel}</Pill>
                                    <Pill>
                                        nr. {act.number}
                                        {act.year === null ? '' : `/${String(act.year)}`}
                                    </Pill>
                                    {act.issuer === null ? null : <Pill>{act.issuer}</Pill>}
                                    {act.inForceFrom === null ? null : (
                                        <Pill tone="locked">
                                            {t((d) => d.pages.legislation.add.inForce).replace(
                                                '{date}',
                                                formatDate(act.inForceFrom, locale),
                                            )}
                                        </Pill>
                                    )}
                                </div>
                            </div>
                            {act.lawId !== null ? (
                                <Pill tone="accent">{t((d) => d.pages.legislation.add.imported)}</Pill>
                            ) : started.has(act.url) ? (
                                <Pill>{t((d) => d.pages.legislation.add.importing)}</Pill>
                            ) : !act.importable ? (
                                <Pill>
                                    {t((d) => d.pages.legislation.add.unsupported).replace('{type}', act.typeLabel)}
                                </Pill>
                            ) : confirming === act.url ? (
                                // Its own line under the act, so the question never squeezes the title.
                                <div className="flex basis-full flex-wrap items-center justify-end gap-2">
                                    <span className="text-xs">{t((d) => d.pages.legislation.add.confirm)}</span>
                                    <PanelButton
                                        onClick={() => {
                                            setConfirming(null);
                                        }}
                                    >
                                        <X className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.legislation.add.cancel)}
                                    </PanelButton>
                                    <PanelButton primary onClick={() => void read(act)}>
                                        <Download className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.legislation.add.confirmImport)}
                                    </PanelButton>
                                </div>
                            ) : (
                                <PanelButton
                                    onClick={() => {
                                        setConfirming(act.url);
                                    }}
                                >
                                    <Download className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.legislation.add.import)}
                                </PanelButton>
                            )}
                        </article>
                    ))
                )}
            </div>
        </PanelDialog>
    );
}

/**
 * Where the search is: reading, with how far it has got and a way to stop it; or why it stopped short.
 * Nothing once every page was read — the acts below are the answer.
 */
function SearchProgress({ search, onStop }: { search: PortalSearchState; onStop: () => void }): JSX.Element | null {
    const { t } = useTranslation();
    const add = t((d) => d.pages.legislation.add);

    if (search.status === 'running') {
        return (
            <div className="search-progress" role="status">
                <Loader2 className="size-4 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                <span className="min-w-0 flex-1 text-xs">
                    {add.searching
                        .replace('{pages}', String(search.pagesRead))
                        .replace('{count}', String(search.acts.length))}
                </span>
                <PanelButton onClick={onStop}>
                    <CircleStop className="size-3.5" aria-hidden="true" />
                    {add.stop}
                </PanelButton>
            </div>
        );
    }

    if (search.status === 'done') {
        return null;
    }

    const message =
        search.status === 'cancelled'
            ? add.stopped
            : search.status === 'incomplete'
              ? add.incomplete
                    .replace('{page}', String(search.pagesRead + 1))
                    .replace('{reason}', failureText(add, search.failure))
              : failureText(add, search.failure);

    return (
        <p className="search-progress search-progress-stopped" role="status">
            <TriangleAlert className="size-4 shrink-0" aria-hidden="true" />
            <span className="min-w-0 flex-1 text-xs">{message}</span>
        </p>
    );
}

/** Why a search stopped short, by the reason the server gave, in this screen's words. */
function failureText(add: Dictionary['pages']['legislation']['add'], reason: string | null): string {
    switch (reason) {
        case 'portal_search_refused':
            return add.searchRefused;
        case 'portal_browser_busy':
            return add.browserBusy;
        case 'portal_browser_unavailable':
            return add.browserUnavailable;
        default:
            return add.portalDown;
    }
}
