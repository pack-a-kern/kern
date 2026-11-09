import { Link, router } from '@inertiajs/react';
import {
    BookOpen,
    CircleCheck,
    EllipsisVertical,
    FileText,
    Gavel,
    History,
    Landmark,
    Newspaper,
    Plus,
    Scale,
    ScrollText,
    Search,
    Trash2,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteLaw, listLaws, searchLegislation } from '../api/legislationApi';
import ConfirmDialog from '../components/ConfirmDialog';
import {
    EmptyState,
    Field,
    Page,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    SearchInput,
    Status,
} from '../components/panel';
import { PanelDate } from '../components/PanelDatePicker';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '../components/ui/dropdown-menu';
import { Skeleton } from '../components/ui/skeleton';
import { useRememberedState } from '../hooks/useRememberedState';
import { useTranslation } from '../i18n';
import { describeApiFailure } from '../lib/apiErrors';
import { formatDate } from '../lib/format';
import { ApiError } from '../lib/http';
import { useSharedAuth } from '../lib/sharedProps';
import AddLawDialog from './legislation/AddLawDialog';
import type { LawSummary, LegislationHit, LegislationSearchResult } from '../types/legislation';

/** Trust tiers as `config/sources.php` declares them; A is the most authoritative. */
const TIERS = ['A', 'B', 'C', 'D'];

/**
 * Legislation: the laws read, and a search across them.
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
}: {
    documentTypes?: string[];
    years?: { min: number; max: number };
}): JSX.Element {
    const { t } = useTranslation();

    // What was searched for is remembered; the results are not — searching again is one click, and a
    // stored result would show texts as they read on a date nobody chose.
    const [query, setQuery] = useRememberedState<string>('legislation.query', '');
    const [tier, setTier] = useRememberedState<string>('legislation.tier', '', {
        isValid: (value) => value === '' || TIERS.includes(value),
    });
    const [asOf, setAsOf] = useState(() => new Date().toISOString().slice(0, 10));

    const [result, setResult] = useState<LegislationSearchResult | null>(null);
    const [searching, setSearching] = useState(false);
    const [forbidden, setForbidden] = useState(false);
    const [adding, setAdding] = useState(false);
    const [reload, setReload] = useState(0);
    const canAdd = useSharedAuth()?.user.permissions.includes('operations.run') === true;

    /*
     * Results hang under the field rather than taking the page.
     *
     * Looking for a provision is a detour, not a destination: the laws stay where they were, and the
     * answer appears over them until it is dismissed or followed. Rendered below the form and
     * absolutely positioned, so the page behind does not jump as results arrive and nothing has to
     * scroll back up afterwards.
     */
    const [resultsOpen, setResultsOpen] = useState(false);
    const searchRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (!resultsOpen) {
            return;
        }

        const dismissOutside = (event: PointerEvent): void => {
            const target = event.target;

            // The form is inside the ref, so pressing Search again — or correcting the query —
            // leaves the results up. Only a press that lands elsewhere means "I am done with these".
            if (searchRef.current !== null && target instanceof Node && !searchRef.current.contains(target)) {
                setResultsOpen(false);
            }
        };

        const dismissOnEscape = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                setResultsOpen(false);
            }
        };

        document.addEventListener('pointerdown', dismissOutside);
        document.addEventListener('keydown', dismissOnEscape);

        return () => {
            document.removeEventListener('pointerdown', dismissOutside);
            document.removeEventListener('keydown', dismissOnEscape);
        };
    }, [resultsOpen]);

    const run = useCallback(async (): Promise<void> => {
        if (query.trim().length < 2) {
            return;
        }

        setSearching(true);
        setResultsOpen(true);

        try {
            setResult(
                // No source filter yet: only one source has ingested content, and a select
                // listing sources with nothing behind them would be a filter that only ever
                // returns nothing. The tier filter already narrows by authority.
                await searchLegislation(query, {
                    ...(tier === '' ? {} : { tier }),
                    asOf,
                }),
            );
            setForbidden(false);
        } catch (cause: unknown) {
            if (cause instanceof ApiError && cause.status === 403) {
                setForbidden(true);

                return;
            }

            setResult(null);

            // The toast carries the failure, so the overlay has nothing left to say: leaving it up
            // would be an empty box claiming zero results for a search that never ran.
            setResultsOpen(false);
            toast.error(
                t((d) => d.pages.legislation.searchFailed),
                {
                    description:
                        cause instanceof ApiError && cause.reason === 'network'
                            ? t((d) => d.errors.network)
                            : t((d) => d.errors.unexpected),
                },
            );
        } finally {
            setSearching(false);
        }
    }, [query, tier, asOf, t]);

    if (forbidden) {
        return (
            <>
                <div className="mx-auto w-full max-w-lg pt-16 text-center">
                    <h1 className="text-lg font-semibold">{t((d) => d.pages.legislation.title)}</h1>
                    <p className="note mt-2">{t((d) => d.pages.legislation.forbidden)}</p>
                </div>
            </>
        );
    }

    const total = result === null ? 0 : result.confirmed.length + result.dateUnknown.length;

    return (
        <>
            <Page title={t((d) => d.pages.legislation.title)} lede={t((d) => d.pages.legislation.description)}>
                {/* The search first: most visits look for a provision, not a law. */}
                <div className="relative" ref={searchRef}>
                    <Panel
                        title={t((d) => d.pages.legislation.searchTitle)}
                        description={t((d) => d.pages.legislation.searchHint)}
                        icon={<Search className="size-4" />}
                        aside={
                            canAdd ? (
                                <PanelButton
                                    onClick={() => {
                                        setAdding(true);
                                    }}
                                >
                                    <Plus className="size-3.5" />
                                    {t((d) => d.pages.legislation.laws.add)}
                                </PanelButton>
                            ) : undefined
                        }
                    >
                        {/*
                        Two columns on a phone, four on a desktop. The date and the button share the
                        last row rather than taking one each: a date picker is half a phone wide and
                        the button beside it is the thing you press after setting it, so stacking them
                        spent a row to say nothing. The two wide controls span both columns.
                    */}
                        <form
                            className="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_11rem_auto]"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void run();
                            }}
                        >
                            <div className="col-span-2 sm:col-span-1">
                                <SearchInput
                                    value={query}
                                    placeholder={t((d) => d.pages.legislation.searchPlaceholder)}
                                    onChange={setQuery}
                                />
                            </div>
                            <div className="col-span-2 sm:col-span-1">
                                <Field label={t((d) => d.pages.legislation.tier)} htmlFor="legislation-tier">
                                    <PanelSelect
                                        id="legislation-tier"
                                        value={tier}
                                        onValueChange={setTier}
                                        placeholder={t((d) => d.pages.legislation.allTiers)}
                                        emptyLabel={t((d) => d.pages.legislation.allTiers)}
                                        options={TIERS.map((value) => ({ value, label: value }))}
                                    />
                                </Field>
                            </div>
                            <Field label={t((d) => d.pages.legislation.asOf)} htmlFor="legislation-as-of">
                                <PanelDate
                                    value={asOf === '' ? null : asOf}
                                    onChange={(next) => {
                                        setAsOf(next ?? '');
                                    }}
                                />
                            </Field>
                            <PanelButton
                                primary
                                disabled={searching || query.trim().length < 2}
                                onClick={() => {
                                    void run();
                                }}
                            >
                                <Search className="size-3.5" />
                                {t((d) => d.pages.legislation.search)}
                            </PanelButton>
                        </form>
                        <p className="note mt-2">{t((d) => d.pages.legislation.asOfHint)}</p>
                    </Panel>

                    {resultsOpen ? (
                        <div
                            className="panel search-results"
                            role="region"
                            aria-label={t((d) => d.pages.legislation.searchTitle)}
                        >
                            <div className="border-border/70 mb-3 flex items-center justify-between gap-2 border-b pb-2">
                                <span className="note tabular-nums">
                                    {searching
                                        ? t((d) => d.common.loading)
                                        : t((d) => d.pages.legislation.resultCount).replace('{count}', String(total))}
                                </span>
                                <button
                                    type="button"
                                    className="row-menu"
                                    aria-label={t((d) => d.common.close)}
                                    onClick={() => {
                                        setResultsOpen(false);
                                    }}
                                >
                                    <X className="size-4" />
                                </button>
                            </div>

                            {searching ? (
                                <div className="space-y-2">
                                    {[0, 1, 2].map((index) => (
                                        <Skeleton key={index} className="tile h-20 w-full" />
                                    ))}
                                </div>
                            ) : result === null ? null : total === 0 ? (
                                <EmptyState
                                    icon={<Scale className="size-5" />}
                                    title={t((d) => d.pages.legislation.empty)}
                                />
                            ) : (
                                <div className="space-y-4">
                                    {/*
                                    Still two groups, still never merged: a text whose effective dates
                                    cover the date asked for and a text whose dates nobody has
                                    established are different claims, and sorting them into one list by
                                    score would erase the only distinction that makes a citation
                                    defensible. A heading each, inside the one overlay.
                                */}
                                    <HitGroup
                                        hits={result.confirmed}
                                        asOf={result.asOf}
                                        title={t((d) => d.pages.legislation.confirmed)}
                                        hint={t((d) => d.pages.legislation.confirmedHint)}
                                        tone="ok"
                                    />
                                    <HitGroup
                                        hits={result.dateUnknown}
                                        asOf={result.asOf}
                                        title={t((d) => d.pages.legislation.dateUnknown)}
                                        hint={t((d) => d.pages.legislation.dateUnknownHint)}
                                        tone="warn"
                                    />
                                </div>
                            )}
                        </div>
                    ) : null}
                </div>

                <Laws reload={reload} />
            </Page>

            {canAdd ? (
                <AddLawDialog
                    open={adding}
                    types={documentTypes ?? []}
                    years={years ?? null}
                    onOpenChange={(open) => {
                        setAdding(open);

                        // Reading is queued: by the time the dialog closes, the act may be in.
                        if (!open) {
                            setReload((count) => count + 1);
                        }
                    }}
                    onStarted={() => {
                        setReload((count) => count + 1);
                    }}
                />
            ) : null}
        </>
    );
}

/** The mark a law's card carries, by the kind of act — a law, a decision, an ordinance. */
const TYPE_ICON: Record<string, LucideIcon | undefined> = {
    LEGE: Landmark,
    HOTARARE: FileText,
    ORDONANTA: ScrollText,
    'ORDONANTA DE URGENTA': ScrollText,
};

/**
 * The laws read from legislatie.just.ro, each as the portal describes it, with how much of it is in.
 *
 * Laid out as the act's own heading reads — its name, then who issued it and where it was published —
 * because that is how a person recognises a law, and the counts say whether the text they are about
 * to read is complete.
 */
function Laws({ reload }: { reload: number }): JSX.Element {
    const { t, locale } = useTranslation();
    const [laws, setLaws] = useState<LawSummary[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [removing, setRemoving] = useState<LawSummary | null>(null);
    const [again, setAgain] = useState(0);
    const mayRemove = useSharedAuth()?.user.permissions.includes('operations.run') === true;

    useEffect(() => {
        let active = true;

        listLaws().then(
            (loaded) => {
                if (active) {
                    setLaws(loaded);
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
    }, [reload, again]);

    const date = (value: string | null): string => (value === null ? '—' : formatDate(value, locale));

    return (
        <section>
            {failed ? (
                <p className="note px-1">{t((d) => d.pages.legislation.laws.failed)}</p>
            ) : laws === null ? (
                <div className="grid gap-2.5 lg:grid-cols-2">
                    {[0, 1].map((index) => (
                        <Skeleton key={index} className="tile h-28 w-full" />
                    ))}
                </div>
            ) : laws.length === 0 ? (
                <EmptyState icon={<Scale className="size-5" />} title={t((d) => d.pages.legislation.laws.empty)} />
            ) : (
                <div className="grid gap-2.5 lg:grid-cols-2">
                    {laws.map((law) => {
                        const Icon = TYPE_ICON[law.type] ?? Scale;
                        const open = (): void => {
                            router.visit(`/legislation/${law.id}`);
                        };

                        return (
                            <article
                                key={law.id}
                                /*
                                    Three columns at every width — mark, name, actions — and two bands
                                    under them that ignore all three. The bands are what a reader
                                    compares between cards, so they start at the card's own edge rather
                                    than in the name's column: indented under the mark they were a third
                                    of the card wide, which is what wrapped a pill onto its own line and
                                    clipped "Versiunea curentă" to an ellipsis while the space beneath
                                    the actions sat empty.
                                */
                                className="panel panel-pad hover:border-info/40 grid cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] gap-x-3 gap-y-2.5 transition-colors"
                                onClick={open}
                            >
                                <div className="bg-muted text-muted-foreground grid size-9 place-items-center rounded-xl">
                                    <Icon className="size-4" />
                                </div>
                                <h3 className="min-w-0 self-center text-sm font-semibold text-balance">
                                    {law.title ?? law.url}
                                </h3>
                                <div className="flex items-start gap-1.5">
                                    {/*
                                        One menu on a phone, the actions themselves on a desktop. A row
                                        of buttons beside the name costs about 290px, which a phone does
                                        not have to give: they wrapped to a line of their own and pushed
                                        everything down. The menu is the same control the taxpayer and
                                        user lists already put at the end of a row.
                                    */}
                                    {/*
                                        `sm:hidden` on a wrapper, not on the button: `.row-menu` is one
                                        of this stylesheet's unlayered component classes, and an
                                        unlayered `display: grid` beats a layered `display: none`
                                        whatever the breakpoint. The menu would have stayed on screen
                                        beside the buttons it replaces.
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
                                                    {t((d) => d.pages.legislation.laws.open)}
                                                </DropdownMenuItem>
                                                {mayRemove ? (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() => {
                                                            setRemoving(law);
                                                        }}
                                                    >
                                                        <Trash2 className="size-4" />
                                                        {t((d) => d.pages.legislation.laws.remove)}
                                                    </DropdownMenuItem>
                                                ) : null}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>

                                    <div className="hidden gap-1.5 sm:flex">
                                        {mayRemove ? (
                                            <PanelButton
                                                onClick={(event) => {
                                                    // The card itself opens the law; this one must not.
                                                    event.stopPropagation();
                                                    setRemoving(law);
                                                }}
                                            >
                                                <Trash2 className="size-3.5" aria-hidden="true" />
                                                {t((d) => d.pages.legislation.laws.remove)}
                                            </PanelButton>
                                        ) : null}
                                        <PanelButton onClick={open}>
                                            <BookOpen className="size-3.5" aria-hidden="true" />
                                            {t((d) => d.pages.legislation.laws.open)}
                                        </PanelButton>
                                    </div>
                                </div>

                                <div className="col-span-full flex flex-wrap gap-1.5">
                                    {law.typeLabel === null ? null : <Pill tone="accent">{law.typeLabel}</Pill>}
                                    {law.officialJournalNumber === null ? null : (
                                        <Pill tone="locked">
                                            {t((d) => d.pages.legislation.laws.journal).replace(
                                                '{number}',
                                                law.officialJournalNumber,
                                            )}
                                        </Pill>
                                    )}
                                    <Pill>
                                        {t((d) => d.pages.legislation.laws.consolidations)
                                            .replace('{read}', String(law.consolidationsRead))
                                            .replace('{listed}', String(law.consolidationsListed))}
                                    </Pill>
                                    {law.consolidationsFailed === 0 ? null : (
                                        <Status tone="warn">
                                            {t((d) => d.pages.legislation.laws.consolidationsFailed).replace(
                                                '{count}',
                                                String(law.consolidationsFailed),
                                            )}
                                        </Status>
                                    )}
                                </div>
                                {/*
                                    Across the whole card rather than beside the title, which is where
                                    the width is: four dates in the title's column left "Current version"
                                    wrapping under its own label and its value a line below the other
                                    three. The rule above them separates what the act *is* from when it
                                    happened — and it is also what fills the space the actions used to
                                    leave empty beneath them.

                                    Not `DetailList`: that reads label-at-one-edge, value-at-the-other
                                    down a fixed 110px label track, and "Versiunea curentă" does not fit
                                    in it. Four figures of one kind read across, not down.
                                */}
                                <dl className="border-border/70 col-span-full grid grid-cols-2 gap-x-3 gap-y-2.5 border-t pt-3 sm:grid-cols-4">
                                    {(
                                        [
                                            [
                                                t((d) => d.pages.legislation.laws.actDate),
                                                law.actDate,
                                                // One mark per kind of date, never four calendars: what
                                                // separates these is the event each one records — the act
                                                // made, printed, in force, last rewritten — and a calendar
                                                // beside every one of them would say only "a date", which
                                                // the value already says.
                                                <Gavel key="act" className="size-3" aria-hidden="true" />,
                                            ],
                                            [
                                                t((d) => d.pages.legislation.laws.publishedOn),
                                                law.publishedOn,
                                                <Newspaper key="pub" className="size-3" aria-hidden="true" />,
                                            ],
                                            [
                                                t((d) => d.pages.legislation.laws.inForceFrom),
                                                law.inForceFrom,
                                                <CircleCheck key="force" className="size-3" aria-hidden="true" />,
                                            ],
                                            [
                                                t((d) => d.pages.legislation.laws.currentVersion),
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
                                            <dd className="mt-0.5 text-xs font-semibold tabular-nums">{date(value)}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </article>
                        );
                    })}
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
                    title={t((d) => d.pages.legislation.laws.removeTitle)}
                    description={removing.title ?? removing.url}
                    detail={
                        <p className="note">
                            {t((d) => d.pages.legislation.laws.removeDetail).replace(
                                '{consolidations}',
                                String(removing.consolidationsRead),
                            )}
                        </p>
                    }
                    confirmLabel={t((d) => d.pages.legislation.laws.remove)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await deleteLaw(removing.id);
                            toast.success(t((d) => d.pages.legislation.laws.removed));
                            setRemoving(null);
                            setAgain((run) => run + 1);
                        } catch (cause) {
                            // A law the fiscal values rest on, or one something else was read from, stays.
                            const reason = cause instanceof ApiError ? cause.reason : undefined;
                            const said =
                                reason === 'LAW_HAS_VALUES'
                                    ? t((d) => d.pages.legislation.laws.removeRefused)
                                    : reason === 'LAW_IS_READ_FROM'
                                      ? t((d) => d.pages.legislation.laws.removeReadFrom)
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
                <Pill tone="locked">{hit.trustTier}</Pill>
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
