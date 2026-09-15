import { useCallback, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { searchLegislation } from '../api/legislationApi';
import AppShell from '../components/AppShell';
import { Field, Page, Panel, PanelButton, PanelSelect, Pill, SearchInput, Status, Toolbar } from '../components/panel';
import { PanelDate } from '../components/PanelDatePicker';
import { Skeleton } from '../components/ui/skeleton';
import { useRememberedState } from '../hooks/useRememberedState';
import { useTranslation } from '../i18n';
import { ApiError } from '../lib/http';
import type { LegislationHit, LegislationSearchResult } from '../types/legislation';

/** Trust tiers as `config/sources.php` declares them; A is the most authoritative. */
const TIERS = ['A', 'B', 'C', 'D'];

/**
 * Legislation search.
 *
 * The screen's one real design constraint is that it must never imply a text was in force on a date
 * nobody has established. `legislatie.just.ro` publishes no machine-readable effective date, so
 * almost every ingested version lands `REQUIRES_REVIEW` with no dates at all — and the server
 * returns those separately from hits whose dates genuinely cover the searched date. The two groups
 * are rendered as two sections with different wording rather than one list sorted by score, because
 * merging them would erase exactly the distinction that makes a citation defensible.
 */
export default function Legislation(): JSX.Element {
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
                <Panel title={t((d) => d.pages.legislation.search)} flush>
                    <Toolbar>
                        <SearchInput
                            value={query}
                            placeholder={t((d) => d.pages.legislation.searchPlaceholder)}
                            onChange={setQuery}
                        />
                        <PanelSelect
                            className="filter-select"
                            value={tier}
                            onValueChange={setTier}
                            placeholder={t((d) => d.pages.legislation.allTiers)}
                            emptyLabel={t((d) => d.pages.legislation.allTiers)}
                            options={TIERS.map((value) => ({ value, label: value }))}
                        />
                        <PanelButton
                            primary
                            disabled={searching || query.trim().length < 2}
                            onClick={() => {
                                void run();
                            }}
                        >
                            {t((d) => d.pages.legislation.search)}
                        </PanelButton>
                    </Toolbar>

                    <div className="p-3">
                        <Field
                            label={t((d) => d.pages.legislation.asOf)}
                            hint={t((d) => d.pages.legislation.asOfHint)}
                            htmlFor="legislation-as-of"
                            className="max-w-xs"
                        >
                            <PanelDate
                                value={asOf === '' ? null : asOf}
                                onChange={(next) => {
                                    setAsOf(next ?? '');
                                }}
                            />
                        </Field>
                    </div>
                </Panel>

                {searching ? (
                    <Panel title={t((d) => d.pages.legislation.search)}>
                        <div className="space-y-2">
                            {[0, 1, 2].map((index) => (
                                <Skeleton key={index} className="tile h-20 w-full" />
                            ))}
                        </div>
                    </Panel>
                ) : result === null ? (
                    <Panel title={t((d) => d.pages.legislation.title)}>
                        <p className="note">{t((d) => d.pages.legislation.idle)}</p>
                    </Panel>
                ) : total === 0 ? (
                    <Panel title={t((d) => d.pages.legislation.title)}>
                        <p className="note">{t((d) => d.pages.legislation.empty)}</p>
                    </Panel>
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
                                        <Hit key={hit.chunkId} hit={hit} />
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
                                        <Hit key={hit.chunkId} hit={hit} />
                                    ))}
                                </div>
                            </Panel>
                        ) : null}
                    </>
                )}
            </Page>
        </AppShell>
    );
}

/** One retrieved chunk: its citation, its provenance, its text, and how it was matched. */
function Hit({ hit }: { hit: LegislationHit }): JSX.Element {
    const { t } = useTranslation();

    return (
        <article className="tile border border-border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold">{hit.citation}</span>
                <Pill tone="accent">{hit.sourceCode}</Pill>
                <Pill>{hit.documentType}</Pill>
                <Pill tone="locked">{hit.trustTier}</Pill>
            </div>

            <p className="mt-2 text-xs leading-relaxed">{hit.text}</p>

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
