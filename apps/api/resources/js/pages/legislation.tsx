import { Link, router } from '@inertiajs/react';
import { FileText, Landmark, Plus, Scale, ScrollText, Search, Trash2, type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteLaw, listLaws, searchLegislation } from '../api/legislationApi';
import AppShell from '../components/AppShell';
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

    const run = useCallback(async (): Promise<void> => {
        if (query.trim().length < 2) {
            return;
        }

        setSearching(true);

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
            <AppShell>
                <div className="mx-auto w-full max-w-lg pt-16 text-center">
                    <h1 className="text-lg font-semibold">{t((d) => d.pages.legislation.title)}</h1>
                    <p className="note mt-2">{t((d) => d.pages.legislation.forbidden)}</p>
                </div>
            </AppShell>
        );
    }

    const total = result === null ? 0 : result.confirmed.length + result.dateUnknown.length;

    return (
        <AppShell>
            <Page title={t((d) => d.pages.legislation.title)} lede={t((d) => d.pages.legislation.description)}>
                {/* The search first: most visits look for a provision, not a law. */}
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
                    <form
                        className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_11rem_auto] sm:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void run();
                        }}
                    >
                        <SearchInput
                            value={query}
                            placeholder={t((d) => d.pages.legislation.searchPlaceholder)}
                            onChange={setQuery}
                        />
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
                            inline
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
                    <>
                        {result.confirmed.length > 0 ? (
                            <Panel
                                title={t((d) => d.pages.legislation.confirmed)}
                                description={t((d) => d.pages.legislation.confirmedHint)}
                                aside={
                                    <Status tone="ok">
                                        {t((d) => d.pages.legislation.resultCount).replace(
                                            '{count}',
                                            String(result.confirmed.length),
                                        )}
                                    </Status>
                                }
                            >
                                <div className="space-y-3">
                                    {result.confirmed.map((hit) => (
                                        <Hit key={hit.chunkId} hit={hit} asOf={result.asOf} />
                                    ))}
                                </div>
                            </Panel>
                        ) : null}

                        {result.dateUnknown.length > 0 ? (
                            <Panel
                                title={t((d) => d.pages.legislation.dateUnknown)}
                                description={t((d) => d.pages.legislation.dateUnknownHint)}
                                aside={
                                    <Status tone="warn">
                                        {t((d) => d.pages.legislation.resultCount).replace(
                                            '{count}',
                                            String(result.dateUnknown.length),
                                        )}
                                    </Status>
                                }
                            >
                                <div className="space-y-3">
                                    {result.dateUnknown.map((hit) => (
                                        <Hit key={hit.chunkId} hit={hit} asOf={result.asOf} />
                                    ))}
                                </div>
                            </Panel>
                        ) : null}
                    </>
                )}

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
        </AppShell>
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
            <div className="mb-2 flex flex-wrap items-end justify-between gap-2 px-1">
                <div>
                    <h2 className="text-sm font-semibold">{t((d) => d.pages.legislation.laws.title)}</h2>
                    <p className="note">{t((d) => d.pages.legislation.laws.hint)}</p>
                </div>
                {laws === null ? null : (
                    <span className="note tabular-nums">
                        {t((d) => d.pages.legislation.laws.count).replace('{count}', String(laws.length))}
                    </span>
                )}
            </div>

            {failed ? (
                <p className="note px-1">{t((d) => d.pages.legislation.laws.failed)}</p>
            ) : laws === null ? (
                <div className="grid gap-2.5 lg:grid-cols-2">
                    {[0, 1].map((index) => (
                        <Skeleton key={index} className="tile h-28 w-full" />
                    ))}
                </div>
            ) : laws.length === 0 ? (
                <EmptyState
                    icon={<Scale className="size-5" />}
                    title={t((d) => d.pages.legislation.laws.empty)}
                />
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
                                className="panel panel-pad hover:border-info/40 grid cursor-pointer grid-cols-[auto_minmax(0,1fr)] gap-3 transition-colors sm:grid-cols-[auto_minmax(0,1fr)_auto]"
                                onClick={open}
                            >
                                <div className="bg-muted text-muted-foreground grid size-9 place-items-center rounded-xl">
                                    <Icon className="size-4" />
                                </div>
                                <div className="min-w-0">
                                    <h3 className="text-sm font-semibold text-balance">{law.title ?? law.url}</h3>
                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
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
                                    <dl className="mt-2.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        {(
                                            [
                                                [t((d) => d.pages.legislation.laws.actDate), law.actDate],
                                                [t((d) => d.pages.legislation.laws.publishedOn), law.publishedOn],
                                                [t((d) => d.pages.legislation.laws.inForceFrom), law.inForceFrom],
                                                [
                                                    t((d) => d.pages.legislation.laws.currentVersion),
                                                    law.lastConsolidation,
                                                ],
                                            ] as const
                                        ).map(([label, value]) => (
                                            <div key={label}>
                                                <dt className="note">{label}</dt>
                                                <dd className="text-xs font-semibold tabular-nums">{date(value)}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </div>
                                <div className="col-start-2 flex items-start gap-1.5 sm:col-start-auto">
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
                                    <PanelButton onClick={open}>{t((d) => d.pages.legislation.laws.open)}</PanelButton>
                                </div>
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
                            // A law the fiscal values rest on is refused, and says how many rest on it.
                            const said =
                                cause instanceof ApiError && cause.reason === 'LAW_HAS_VALUES'
                                    ? t((d) => d.pages.legislation.laws.removeRefused)
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
