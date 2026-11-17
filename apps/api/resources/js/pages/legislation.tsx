import { Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpen,
    CircleCheck,
    EllipsisVertical,
    Gavel,
    History,
    Newspaper,
    Plus,
    Scale,
    Search,
    SlidersHorizontal,
    Trash2,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteLaw, listLaws, searchLegislation, type LawFilter } from '../api/legislationApi';
import ConfirmDialog from '../components/ConfirmDialog';
import Pagination from '../components/Pagination';
import {
    EmptyState,
    Page,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    SearchInput,
    Status,
    ToolbarRow,
    ToolbarRows,
} from '../components/panel';
import { PanelDate } from '../components/PanelDatePicker';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '../components/ui/dropdown-menu';
import { Checkbox } from '../components/ui/checkbox';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../components/ui/sheet';
import { Skeleton } from '../components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipTrigger } from '../components/ui/tooltip';
import { useClamped } from '../hooks/useClamped';
import { useMediaQuery } from '../hooks/useMediaQuery';
import { useRememberedPaging, useRememberedState } from '../hooks/useRememberedState';
import { useTranslation } from '../i18n';
import { actKind } from '../lib/actKinds';
import { describeApiFailure } from '../lib/apiErrors';
import { subscribeToLawDeletions } from '../lib/echo';
import { formatDate } from '../lib/format';
import { ApiError } from '../lib/http';
import { useSharedAuth } from '../lib/sharedProps';
import { cn } from '../lib/utils';
import AddLawDialog from './legislation/AddLawDialog';
import LawWatches from './legislation/LawWatches';
import type { LawsPage, LawSummary, LegislationHit, LegislationSearchResult } from '../types/legislation';

/** The filter as the toolbar holds it — "" for "any" — as the server asks for it. */
function lawFilter(type: string, year: string, watch: string): LawFilter {
    return {
        type: type === '' ? null : type,
        year: year === '' ? null : Number(year),
        watch: watch === '' ? null : watch,
    };
}

/**
 * Legislation: the laws read, and a search of their text — one toolbar for both.
 *
 * One filter — a type, a year, a watch — decides which laws are listed and, once words are searched, which
 * laws are searched: the server applies the same filter to both. A search's answer takes the list's place
 * until "Back to laws", and is asked again whenever its date or the filter changes.
 *
 * The screen's one real design constraint is that it must never imply a text was in force on a date
 * nobody has established. `legislatie.just.ro` publishes no machine-readable effective date, so
 * almost every ingested version lands `REQUIRES_REVIEW` with no dates at all — and the server
 * returns those separately from hits whose dates genuinely cover the searched date. The two groups
 * are rendered as two sections with different wording rather than one list sorted by score, because
 * merging them would erase exactly the distinction that makes a citation defensible.
 */
export default function Legislation({
    documentTypes,
    years,
    importMax,
    byMeaning,
}: {
    documentTypes?: string[];
    years?: { min: number; max: number };
    /** The most acts one "Import selected" takes. */
    importMax: number;
    /** Whether this installation searches the laws by meaning as well as by their words. */
    byMeaning: boolean;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.legislation);
    const canAdd = useSharedAuth()?.user.permissions.includes('operations.run') === true;

    // The words and the filter are remembered, like every other screen's; the results are not — searching
    // again is one click, and a stored result would show texts as they read on a date nobody chose. A watch
    // only for whoever may filter by one.
    const [query, setQuery] = useRememberedState<string>('legislation.query', '');
    const [type, setType] = useRememberedState<string>('legislation.laws.type', '');
    const [year, setYear] = useRememberedState<string>('legislation.laws.year', '');
    const [watch, setWatch] = useRememberedState<string>('legislation.laws.watch', '', {
        isValid: (value) => canAdd || value === '',
    });
    const [asOf, setAsOf] = useState(() => new Date().toISOString().slice(0, 10));
    const [searched, setSearched] = useState<string | null>(null);
    // Kept with the question it answers: an answer to an earlier question is not shown as this one's.
    const [answered, setAnswered] = useState<{ asked: string; result: LegislationSearchResult } | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [answer, setAnswer] = useState<LawsPage | null>(null);
    const { page, pageSize, setPage, setPageSize } = useRememberedPaging('legislation.laws');
    const [failed, setFailed] = useState(false);
    const [adding, setAdding] = useState(false);
    const [narrowingOpen, setNarrowingOpen] = useState(false);
    const wide = useMediaQuery('(min-width: 40rem)');
    const [reload, setReload] = useState(0);
    const [watched, setWatched] = useState(0);
    const filtered = type !== '' || year !== '' || watch !== '';
    const asked = JSON.stringify([searched, asOf, type, year, watch]);
    // Stable, so the watches dialog keeps its one subscription to the socket.
    const lawsChanged = useCallback((): void => {
        setReload((run) => run + 1);
    }, []);

    useEffect(() => {
        let active = true;

        listLaws(lawFilter(type, year, watch), page, pageSize).then(
            (loaded) => {
                if (active) {
                    // A page past the last — its laws deleted, or fewer kept by a filter — is the last.
                    if (loaded.laws.length === 0 && loaded.total > 0) {
                        setPage(Math.ceil(loaded.total / pageSize));

                        return;
                    }

                    setAnswer(loaded);
                    setFailed(false);
                    // A remembered choice the server no longer offers — its laws deleted — is let go.
                    forget(
                        type,
                        setType,
                        loaded.filters.types.map((one) => one.value),
                    );
                    forget(year, setYear, loaded.filters.years.map(String));
                    forget(
                        watch,
                        setWatch,
                        loaded.filters.watches.map((one) => one.id),
                    );
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [reload, type, year, watch, page, pageSize, setType, setYear, setWatch, setPage]);

    useEffect(() => {
        if (searched === null) {
            return;
        }

        let active = true;

        searchLegislation(searched, asOf, lawFilter(type, year, watch)).then(
            (found) => {
                if (active) {
                    setAnswered({ asked, result: found });
                }
            },
            (cause: unknown) => {
                if (!active) {
                    return;
                }

                if (cause instanceof ApiError && cause.status === 403) {
                    setForbidden(true);

                    return;
                }

                // The toast carries the failure; the list comes back rather than an empty answer claiming
                // nothing was found by a search that never ran.
                setSearched(null);
                toast.error(words.searchFailed, {
                    description:
                        cause instanceof ApiError && cause.reason === 'network'
                            ? t((d) => d.errors.network)
                            : t((d) => d.errors.unexpected),
                });
            },
        );

        return () => {
            active = false;
        };
    }, [asked, searched, asOf, type, year, watch, words.searchFailed, t]);

    if (forbidden) {
        return (
            <div className="mx-auto w-full max-w-lg pt-16 text-center">
                <h1 className="text-lg font-semibold">{words.title}</h1>
                <p className="note mt-2">{words.forbidden}</p>
            </div>
        );
    }

    const filters = answer?.filters ?? null;
    const result = answered?.asked === asked ? answered.result : null;
    const search = (): void => {
        const typed = query.trim();

        if (typed.length >= 2) {
            setSearched(typed);
        }
    };

    const today = new Date().toISOString().slice(0, 10);
    // A filter changed keeps other laws: the list starts again at their first page.
    const narrowed =
        (set: (value: string) => void) =>
        (value: string): void => {
            set(value);
            setPage(1);
        };
    // How many narrow the list or the search: the three filters, and a date other than today.
    const active = [type, year, watch].filter((one) => one !== '').length + (asOf === today ? 0 : 1);
    const clear =
        active === 0 ? undefined : (
            <PanelButton
                inline
                onClick={() => {
                    setType('');
                    setYear('');
                    setWatch('');
                    setAsOf(today);
                    setPage(1);
                }}
            >
                <X className="size-3.5" aria-hidden="true" />
                {words.laws.clearFilters}
            </PanelButton>
        );
    // The same selects in the toolbar's row or, on a phone, in the sheet.
    const narrowing =
        filters === null ? null : (
            <>
                <FilterSelect
                    label={words.laws.type}
                    value={type}
                    onChange={narrowed(setType)}
                    anyLabel={words.laws.anyType}
                    options={filters.types}
                />
                <FilterSelect
                    label={words.laws.year}
                    value={year}
                    onChange={narrowed(setYear)}
                    anyLabel={words.laws.anyYear}
                    options={filters.years.map((one) => ({ value: String(one), label: String(one) }))}
                />
                {filters.watches.length === 0 ? null : (
                    <FilterSelect
                        label={words.laws.watch}
                        value={watch}
                        onChange={narrowed(setWatch)}
                        anyLabel={words.laws.anyWatch}
                        options={filters.watches.map((one) => ({ value: one.id, label: one.words }))}
                    />
                )}
            </>
        );
    // The date decides which versions a search finds, not which laws are listed: apart from the filters.
    const versions = (
        <label className="flex items-center gap-2" title={words.asOfHint}>
            <span className="note shrink-0">{words.asOf}</span>
            <PanelDate
                value={asOf}
                onChange={(next) => {
                    setAsOf(next ?? today);
                }}
            />
        </label>
    );

    const searchButton = (
        <PanelButton
            primary
            inline
            className={wide ? undefined : 'ml-auto'}
            disabled={query.trim().length < 2}
            onClick={search}
        >
            <Search className="size-3.5" aria-hidden="true" />
            {words.search}
        </PanelButton>
    );

    return (
        <>
            <Page title={words.title} lede={byMeaning ? words.description : words.descriptionWords}>
                <Panel
                    title={words.searchTitle}
                    description={words.searchScope}
                    icon={<Search className="size-4" />}
                    flush
                    aside={
                        canAdd ? (
                            <div className="flex flex-wrap gap-1.5">
                                <LawWatches reload={watched} onLawsChanged={lawsChanged} />
                                <PanelButton
                                    aria-label={words.laws.add}
                                    onClick={() => {
                                        setAdding(true);
                                    }}
                                >
                                    <Plus className="size-3.5" aria-hidden="true" />
                                    {/* A phone's header has room for the marks, not their words. */}
                                    <span className="hidden sm:inline">{words.laws.add}</span>
                                </PanelButton>
                            </div>
                        ) : undefined
                    }
                >
                    <ToolbarRows>
                        <ToolbarRow>
                            {/* `contents`: the form only carries Enter; its controls are the row's own. */}
                            <form
                                className="contents"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    search();
                                }}
                            >
                                <SearchInput value={query} placeholder={words.searchPlaceholder} onChange={setQuery} />
                                {/*
                                    A phone: under the field, the filters behind one button that says how many are
                                    set, at the left of the search button's line — four controls stacked would push
                                    the laws off the screen.
                                */}
                                {wide ? null : (
                                    <>
                                        <PanelButton
                                            inline
                                            // Drawn at once, so the line does not change under a finger; it opens once the
                                            // filters' choices are in.
                                            disabled={filters === null}
                                            onClick={() => {
                                                setNarrowingOpen(true);
                                            }}
                                        >
                                            <SlidersHorizontal className="size-3.5" aria-hidden="true" />
                                            {active === 0
                                                ? words.laws.filterLabel
                                                : words.laws.filterCount.replace('{count}', String(active))}
                                        </PanelButton>
                                        {clear}
                                    </>
                                )}
                                {searchButton}
                            </form>
                        </ToolbarRow>
                        {wide ? (
                            <ToolbarRow
                                divided
                                end={
                                    <>
                                        {versions}
                                        {clear}
                                    </>
                                }
                            >
                                {narrowing}
                            </ToolbarRow>
                        ) : null}
                    </ToolbarRows>
                    <Sheet open={narrowingOpen} onOpenChange={setNarrowingOpen}>
                        <SheetContent side="bottom" className="gap-0 rounded-t-2xl p-0">
                            <SheetHeader className="border-b p-4">
                                <SheetTitle className="dialog-title">{words.laws.filterLabel}</SheetTitle>
                            </SheetHeader>
                            <div className="grid gap-3 p-4">
                                {narrowing}
                                {versions}
                            </div>
                        </SheetContent>
                    </Sheet>
                </Panel>

                {searched === null ? (
                    <LawList
                        laws={answer?.laws ?? null}
                        total={answer?.total ?? 0}
                        page={page}
                        pageSize={pageSize}
                        onPageChange={setPage}
                        onPageSizeChange={setPageSize}
                        failed={failed}
                        filtered={filtered}
                        mayRemove={canAdd}
                        onRemoved={lawsChanged}
                    />
                ) : (
                    <SearchAnswer
                        result={result}
                        scoped={filtered}
                        onBack={() => {
                            setSearched(null);
                        }}
                    />
                )}
            </Page>

            {canAdd ? (
                <AddLawDialog
                    open={adding}
                    types={documentTypes ?? []}
                    years={years ?? null}
                    importMax={importMax}
                    onOpenChange={(open) => {
                        setAdding(open);

                        // Reading is queued: by the time the dialog closes, the act may be in.
                        if (!open) {
                            lawsChanged();
                        }
                    }}
                    onStarted={lawsChanged}
                    onWatched={() => {
                        setWatched((count) => count + 1);
                    }}
                />
            ) : null}
        </>
    );
}

/**
 * A search's answer, in the list's place: back to the laws, how many were found — and, while a filter
 * narrows the laws, that only those were searched — then the two groups, never merged.
 */
function SearchAnswer(props: {
    result: LegislationSearchResult | null;
    scoped: boolean;
    onBack: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.legislation);
    const { result } = props;
    const total = result === null ? 0 : result.confirmed.length + result.dateUnknown.length;

    return (
        <section className="panel panel-pad space-y-4" aria-label={words.searchTitle}>
            <div className="border-border/70 flex flex-wrap items-center gap-2 border-b pb-3">
                <PanelButton onClick={props.onBack}>
                    <ArrowLeft className="size-3.5" aria-hidden="true" />
                    {words.backToLaws}
                </PanelButton>
                <span className="note tabular-nums" role="status">
                    {result === null ? t((d) => d.common.loading) : words.resultCount.replace('{count}', String(total))}
                </span>
                {props.scoped ? <span className="note ml-auto">{words.searchScoped}</span> : null}
            </div>

            {result === null ? (
                <div className="space-y-2">
                    {[0, 1, 2].map((index) => (
                        <Skeleton key={index} className="tile h-20 w-full" />
                    ))}
                </div>
            ) : total === 0 ? (
                <EmptyState icon={<Scale className="size-5" />} title={words.empty} />
            ) : (
                <>
                    <HitGroup
                        hits={result.confirmed}
                        asOf={result.asOf}
                        title={words.confirmed}
                        hint={words.confirmedHint}
                        tone="ok"
                    />
                    <HitGroup
                        hits={result.dateUnknown}
                        asOf={result.asOf}
                        title={words.dateUnknown}
                        hint={words.dateUnknownHint}
                        tone="warn"
                    />
                </>
            )}
        </section>
    );
}

/** The mark a law's card carries, by the kind of act — a law, a decision, an ordinance. */

/**
 * The laws read from legislatie.just.ro, each as the portal describes it, with how much of it is in — those
 * the toolbar's filter keeps, as the server filters them.
 *
 * Laid out as the act's own heading reads — its name, then who issued it and where it was published —
 * because that is how a person recognises a law, and the counts say whether the text they are about
 * to read is complete.
 */
function LawList(props: {
    laws: LawSummary[] | null;
    /** How many laws the filter keeps, across every page. */
    total: number;
    page: number;
    pageSize: number;
    onPageChange: (page: number) => void;
    onPageSizeChange: (pageSize: number) => void;
    failed: boolean;
    filtered: boolean;
    mayRemove: boolean;
    onRemoved: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.legislation.laws);
    const [removing, setRemoving] = useState<LawSummary | null>(null);
    const [ignore, setIgnore] = useState(true);
    const date = (value: string | null): string => (value === null ? '—' : formatDate(value, locale));
    const { laws } = props;
    const userId = useSharedAuth()?.user.id ?? null;
    const onRemoved = props.onRemoved;
    // The ids this screen asked to delete and is waiting on. Removing a law is minutes of work on the
    // queue, too long to hold the request open; the socket says when each is done, and only then does
    // the law come off the list. A ref, not state: it is read in a callback, never drawn.
    const deleting = useRef<Set<string>>(new Set());

    useEffect(
        () =>
            userId === null
                ? undefined
                : subscribeToLawDeletions(userId, (lawId, status) => {
                      if (!deleting.current.has(lawId)) {
                          return;
                      }

                      deleting.current.delete(lawId);

                      if (status === 'deleted') {
                          toast.success(words.removed);
                          onRemoved();
                      } else {
                          toast.error(words.removeFailed);
                      }
                  }),
        [userId, onRemoved, words.removed, words.removeFailed],
    );

    return (
        <section>
            {props.failed ? (
                <p className="note px-1">{words.failed}</p>
            ) : laws === null ? (
                <div className="grid gap-2.5 lg:grid-cols-2">
                    {[0, 1].map((index) => (
                        <Skeleton key={index} className="tile h-28 w-full" />
                    ))}
                </div>
            ) : laws.length === 0 ? (
                <EmptyState icon={<Scale className="size-5" />} title={props.filtered ? words.noMatch : words.empty} />
            ) : (
                <div className="space-y-2.5">
                    <div className="grid gap-2.5 lg:grid-cols-2">
                        {laws.map((law) => (
                            <LawCard
                                key={law.id}
                                law={law}
                                date={date}
                                mayRemove={props.mayRemove}
                                onRemove={() => {
                                    setIgnore(true);
                                    setRemoving(law);
                                }}
                            />
                        ))}
                    </div>
                    {/* The cards are cards of their own, not rows of one: the pager is a card's foot under them. */}
                    <div className="panel">
                        <div className="panel-foot">
                            <Pagination
                                page={props.page}
                                pageSize={props.pageSize}
                                total={props.total}
                                unit={words.unit}
                                onPageChange={props.onPageChange}
                                onPageSizeChange={props.onPageSizeChange}
                            />
                        </div>
                    </div>
                </div>
            )}

            {removing === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setRemoving(null);
                        }
                    }}
                    title={words.removeTitle}
                    description={removing.title ?? removing.url}
                    detail={
                        <div className="space-y-3">
                            <p className="note">
                                {words.removeDetail.replace('{consolidations}', String(removing.consolidationsRead))}
                            </p>
                            {/* Only for an act a watch read in: the only kind a watch would read in again. */}
                            {removing.watchId === null ? null : (
                                <label htmlFor="law-ignore" className="flex items-start gap-2.5">
                                    <Checkbox
                                        id="law-ignore"
                                        checked={ignore}
                                        onCheckedChange={(next) => {
                                            setIgnore(next === true);
                                        }}
                                    />
                                    <span className="space-y-0.5">
                                        <span className="block text-xs font-medium">{words.ignoreInWatches}</span>
                                        <span className="note block">{words.ignoreInWatchesHint}</span>
                                    </span>
                                </label>
                            )}
                        </div>
                    }
                    confirmLabel={words.remove}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await deleteLaw(removing.id, removing.watchId !== null && ignore);
                            // The request only starts the removal. The law stays on the list, going, until
                            // the socket says it is done (the subscription above), so a big law does not
                            // hold the request open until the gateway times it out.
                            deleting.current.add(removing.id);
                            toast(words.removing);
                            setRemoving(null);
                        } catch (cause) {
                            // A law the fiscal values rest on, or one someone holds bonds of, stays.
                            const reason = cause instanceof ApiError ? cause.reason : undefined;
                            const said =
                                reason === 'LAW_HAS_VALUES'
                                    ? words.removeRefused
                                    : reason === 'LAW_IS_READ_FROM'
                                      ? words.removeReadFrom
                                      : describeApiFailure(cause, t);

                            toast.error(said);
                            throw cause;
                        }
                    }}
                />
            )}
        </section>
    );
}

/** Lets a chosen filter go when it is not among those offered. */
function forget(chosen: string, set: (next: string) => void, offered: string[]): void {
    if (chosen !== '' && !offered.includes(chosen)) {
        set('');
    }
}

/** One filter in the toolbar: its choices are the server's, with "any" first, named by its label. */
function FilterSelect(props: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    anyLabel: string;
    options: { value: string; label: string }[];
}): JSX.Element {
    return (
        <PanelSelect
            className="filter-select"
            aria-label={props.label}
            value={props.value}
            onValueChange={props.onChange}
            placeholder={props.anyLabel}
            emptyLabel={props.anyLabel}
            options={props.options}
        />
    );
}

/**
 * One law's card: its mark, its name and its actions; under them its badges and its four dates, pinned to
 * the card's bottom edge (`.law-card`) so they line up with the card beside it whatever each name's length.
 * The name is clamped to two lines (`.law-title`).
 */
function LawCard(props: {
    law: LawSummary;
    date: (value: string | null) => string;
    mayRemove: boolean;
    onRemove: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.legislation.laws);
    const { law } = props;
    const kind = actKind(law.type);
    const name = law.title ?? law.url;
    const open = (): void => {
        router.visit(`/legislation/${law.id}`);
    };

    return (
        <article
            /*
                Three columns at every width — mark, name, actions — and two bands under them that ignore
                all three. The bands are what a reader compares between cards, so they start at the card's
                own edge rather than in the name's column.
            */
            className="panel panel-pad law-card hover:border-info/40 grid cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] gap-x-3 gap-y-2.5 transition-colors"
            onClick={open}
        >
            <div className={cn('tone grid size-9 place-items-center self-start rounded-xl', kind.tone)}>
                <kind.icon className="size-4" aria-hidden="true" />
            </div>
            <LawTitle name={name} />
            <div className="flex items-start gap-1.5 self-start">
                {/*
                    One menu on a phone, the actions themselves on a desktop: a row of buttons beside the name
                    costs about 290px, which a phone does not have to give. `sm:hidden` on a wrapper, not on
                    the button: `.row-menu` is unlayered, and its `display: grid` beats a layered `none`.
                */}
                <div className="sm:hidden">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="row-menu"
                                aria-label={t((d) => d.common.actions)}
                                onClick={(event) => {
                                    // The card itself opens the law; the menu must not.
                                    event.stopPropagation();
                                }}
                            >
                                <EllipsisVertical className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuItem onSelect={open}>
                                <BookOpen className="size-4" />
                                {words.open}
                            </DropdownMenuItem>
                            {props.mayRemove ? (
                                <DropdownMenuItem variant="destructive" onSelect={props.onRemove}>
                                    <Trash2 className="size-4" />
                                    {words.remove}
                                </DropdownMenuItem>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <div className="hidden gap-1.5 sm:flex">
                    {props.mayRemove ? (
                        <PanelButton
                            onClick={(event) => {
                                // The card itself opens the law; this one must not.
                                event.stopPropagation();
                                props.onRemove();
                            }}
                        >
                            <Trash2 className="size-3.5" aria-hidden="true" />
                            {words.remove}
                        </PanelButton>
                    ) : null}
                    <PanelButton onClick={open}>
                        <BookOpen className="size-3.5" aria-hidden="true" />
                        {words.open}
                    </PanelButton>
                </div>
            </div>

            <div className="law-badges col-span-full">
                {law.typeLabel === null ? null : <Pill className={cn('tone', kind.tone)}>{law.typeLabel}</Pill>}
                {law.officialJournalNumber === null ? null : (
                    <Pill tone="locked">{words.journal.replace('{number}', law.officialJournalNumber)}</Pill>
                )}
                <Pill>
                    {words.consolidations
                        .replace('{read}', String(law.consolidationsRead))
                        .replace('{listed}', String(law.consolidationsListed))}
                </Pill>
                {law.readingStopped === null ? null : (
                    <Pill className="bg-destructive/10 text-destructive">
                        <TriangleAlert aria-hidden="true" />
                        {words.readingStopped}
                    </Pill>
                )}
                {law.consolidationsFailed === 0 ? null : (
                    <Pill className="bg-warning/15 text-warning">
                        <TriangleAlert aria-hidden="true" />
                        {words.consolidationsFailed.replace('{count}', String(law.consolidationsFailed))}
                    </Pill>
                )}
                {law.flags.map((flag) =>
                    flag.code === 'bonds_skipped' ? (
                        <Pill key={flag.code} className="bg-warning/15 text-warning">
                            <TriangleAlert aria-hidden="true" />
                            {words.flags.bonds_skipped.replace('{count}', String(flag.count))}
                        </Pill>
                    ) : null,
                )}
            </div>
            {/*
                Across the whole card rather than beside the title, which is where the width is. Not
                `DetailList`: that reads label-at-one-edge, value-at-the-other down a fixed label track, and
                "Versiunea curentă" does not fit in it. Four figures of one kind read across, not down.
            */}
            <dl className="border-border/70 col-span-full grid grid-cols-2 gap-x-3 gap-y-2.5 border-t pt-3 sm:grid-cols-4">
                {(
                    [
                        // One mark per kind of date — the act made, printed, in force, last rewritten — never
                        // four calendars, which would say only "a date".
                        [words.actDate, law.actDate, <Gavel key="act" className="size-3" aria-hidden="true" />],
                        [
                            words.publishedOn,
                            law.publishedOn,
                            <Newspaper key="pub" className="size-3" aria-hidden="true" />,
                        ],
                        [
                            words.inForceFrom,
                            law.inForceFrom,
                            <CircleCheck key="force" className="size-3" aria-hidden="true" />,
                        ],
                        [
                            words.currentVersion,
                            law.lastConsolidation,
                            <History key="version" className="size-3" aria-hidden="true" />,
                        ],
                    ] as const
                ).map(([label, value, icon]) => (
                    <div key={label} className="min-w-0">
                        <dt className="note flex items-center gap-1.5">
                            <span className="opacity-60">{icon}</span>
                            <span className="truncate">{label}</span>
                        </dt>
                        <dd className="mt-0.5 text-xs font-semibold tabular-nums">{props.date(value)}</dd>
                    </div>
                ))}
            </dl>
        </article>
    );
}

/**
 * A law's name, clamped to two lines — and, only when the clamp cuts it, the whole of it in a tooltip on
 * the "…" that ends it, not on all the words.
 */
function LawTitle({ name }: { name: string }): JSX.Element {
    const title = useRef<HTMLHeadingElement>(null);
    const clamped = useClamped(title);

    return (
        <h3 ref={title} className="law-title relative min-w-0 self-start">
            {name}
            {clamped ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        {/* Over the clamp's own "…", which no event reaches: the same mark, drawn where it is. */}
                        <span tabIndex={0} className="bg-card absolute right-0 bottom-0 cursor-help pl-0.5">
                            …
                        </span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-md">{name}</TooltipContent>
                </Tooltip>
            ) : null}
        </h3>
    );
}

/** One retrieved chunk: its citation, its provenance, its text, and how it was matched. */
/**
 * One of the two result groups, with its heading and count — or nothing, when it is empty.
 *
 * Written once rather than twice: the groups differ in their words and their tone, not in their
 * shape, and the empty case is the common one (most of this corpus has no effective dates, so a
 * search often fills one group and leaves the other at zero).
 */
function HitGroup(props: {
    hits: LegislationHit[];
    asOf: string;
    title: string;
    hint: string;
    tone: 'ok' | 'warn';
}): JSX.Element | null {
    const { t } = useTranslation();

    if (props.hits.length === 0) {
        return null;
    }

    return (
        <section>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold">{props.title}</h3>
                    <p className="note">{props.hint}</p>
                </div>
                <Status tone={props.tone}>
                    {t((d) => d.pages.legislation.resultCount).replace('{count}', String(props.hits.length))}
                </Status>
            </div>

            <div className="space-y-3">
                {props.hits.map((hit) => (
                    <Hit key={hit.chunkId} hit={hit} asOf={props.asOf} />
                ))}
            </div>
        </section>
    );
}

function Hit({ hit, asOf }: { hit: LegislationHit; asOf: string }): JSX.Element {
    const { t } = useTranslation();

    return (
        <article className="tile border border-border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold">{hit.citation}</span>
                <Pill tone="accent">{hit.sourceCode}</Pill>
                <Pill>{hit.documentType}</Pill>
                <Link
                    className="text-info ml-auto text-xs underline underline-offset-2"
                    href={`/legislation/${hit.sourceDocumentId}?${new URLSearchParams({ as_of: asOf, provision: hit.citation }).toString()}`}
                >
                    {t((d) => d.pages.legislation.openInLaw)}
                </Link>
            </div>

            <p className="mt-2 text-xs leading-relaxed whitespace-pre-line">{hit.text}</p>

            <div className="note mt-2 flex flex-wrap gap-x-4 gap-y-1">
                {hit.effectiveFrom !== null ? (
                    <span>
                        {t((d) => d.pages.legislation.effectiveFrom)} {hit.effectiveFrom}
                    </span>
                ) : null}
                {hit.effectiveUntil !== null ? (
                    <span>
                        {t((d) => d.pages.legislation.effectiveUntil)} {hit.effectiveUntil}
                    </span>
                ) : null}
                {hit.publicationDate !== null ? (
                    <span>
                        {t((d) => d.pages.legislation.published)} {hit.publicationDate}
                    </span>
                ) : null}
                {hit.chunkCount > 1 ? (
                    <span>
                        {t((d) => d.pages.legislation.chunkOf)
                            .replace('{index}', String(hit.chunkIndex + 1))
                            .replace('{count}', String(hit.chunkCount))}
                    </span>
                ) : null}
                {/* Both halves of the score, not just the total: whether a hit came from wording or
                    from meaning is the difference between "the law uses your words" and "the law
                    means what you asked", and a reader judging a citation deserves to know which. */}
                <span>
                    {t((d) => d.pages.legislation.matchedBy)} — {t((d) => d.pages.legislation.byWords)}{' '}
                    {hit.lexicalRank.toFixed(3)}
                    {hit.semanticSimilarity === null
                        ? ''
                        : `, ${t((d) => d.pages.legislation.byMeaning)} ${hit.semanticSimilarity.toFixed(3)}`}
                </span>
            </div>
        </article>
    );
}
