import { Link } from '@inertiajs/react';
import { CalendarCheck, ChartCandlestick, Coins, Percent, SearchX, X } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';

import { listInstruments } from '../../api/investmentsApi';
import Pagination from '../../components/Pagination';
import {
    CellLabel,
    DataTable,
    EmptyState,
    Page,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    SearchInput,
    TableSkeleton,
    Tabs,
    ToolbarRow,
    ToolbarRows,
} from '../../components/panel';
import { useMediaQuery } from '../../hooks/useMediaQuery';
import { useRememberedPaging, useRememberedState } from '../../hooks/useRememberedState';
import { useTranslation } from '../../i18n';
import { formatDate } from '../../lib/format';
import { INSTRUMENT_KIND_LOOK } from '../../lib/instrumentKinds';
import { spanFromNow } from '../../lib/spanFromNow';
import { cn } from '../../lib/utils';
import {
    INSTRUMENT_KINDS,
    INSTRUMENT_STATUSES,
    PROGRAMMES,
    type Instrument,
    type InstrumentKind,
    type InstrumentPage,
    type InstrumentStatus,
    type Programme,
} from '../../types/investments';

/** The words a search waits for before it asks — a code is at least two characters, as the server takes. */
const MIN_SEARCH = 2;

/**
 * The market (ADR 0025 §11): government bonds, the shares and bonds listed on BVB, each with its latest
 * price — active by default. One card to narrow it: the words or code searched, the kinds as tabs that
 * count what each would show (on a phone, a select), the status and, for government bonds, the programme.
 * A numbered page at a time, remembered like every list's. Everything shown is computed by the server; a
 * bond's price is its percent of the face value.
 */
export default function Market(): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.market);
    const [kind, setKind] = useRememberedState<InstrumentKind | ''>('investments.market.kind', '', {
        isValid: (value) => value === '' || INSTRUMENT_KINDS.includes(value),
    });
    const [status, setStatus] = useRememberedState<InstrumentStatus>('investments.market.status', 'ACTIVE', {
        isValid: (value) => INSTRUMENT_STATUSES.includes(value),
    });
    const [programme, setProgramme] = useRememberedState<Programme | ''>('investments.market.programme', '', {
        isValid: (value) => value === '' || PROGRAMMES.includes(value),
    });
    const [search, setSearch] = useState('');
    const { page, pageSize, setPage, setPageSize } = useRememberedPaging('investments.market');
    const [answer, setAnswer] = useState<InstrumentPage | null>(null);
    const [failed, setFailed] = useState(false);
    const wide = useMediaQuery('(min-width: 40rem)');

    const query = search.trim().length >= MIN_SEARCH ? search.trim() : '';
    // The programme is a government bond's: with any other kind chosen it narrows nothing.
    const programmeShown = kind === 'GOVERNMENT_BOND' ? programme : '';

    useEffect(() => {
        let current = true;

        listInstruments({
            ...(kind === '' ? {} : { kind }),
            ...(programmeShown === '' ? {} : { programme: programmeShown }),
            status,
            q: query,
            page,
            perPage: pageSize,
        })
            .then((loaded) => {
                if (!current) {
                    return;
                }

                // A page past the last — fewer kept by a filter — is the last.
                if (loaded.data.length === 0 && loaded.total > 0) {
                    setPage(Math.ceil(loaded.total / pageSize));

                    return;
                }

                setAnswer(loaded);
                setFailed(false);
            })
            .catch(() => {
                if (current) {
                    setFailed(true);
                }
            });

        return () => {
            current = false;
        };
    }, [kind, programmeShown, status, query, page, pageSize, setPage]);

    // A filter changed keeps other instruments: the list starts again at their first page.
    const narrowed =
        <T,>(set: (value: T) => void) =>
        (value: T): void => {
            set(value);
            setPage(1);
        };
    const clear =
        status === 'ACTIVE' && programmeShown === '' ? undefined : (
            <PanelButton
                inline
                // On a phone it takes half a line beside the last select, as a select would.
                className={wide ? undefined : 'toolbar-half'}
                onClick={() => {
                    setStatus('ACTIVE');
                    setProgramme('');
                    setPage(1);
                }}
            >
                <X className="size-3.5" aria-hidden="true" />
                {words.clearFilters}
            </PanelButton>
        );
    const total = answer?.countAll ?? 0;
    const selects = (
        <>
            <PanelSelect
                className="filter-select"
                aria-label={words.status}
                value={status}
                onValueChange={(value) => {
                    narrowed(setStatus)(INSTRUMENT_STATUSES.find((known) => known === value) ?? 'ACTIVE');
                }}
                options={INSTRUMENT_STATUSES.map((value) => ({ value, label: words.statuses[value] }))}
            />
            {kind === 'GOVERNMENT_BOND' ? (
                <PanelSelect
                    className="filter-select"
                    aria-label={words.programme}
                    value={programme}
                    onValueChange={(value) => {
                        narrowed(setProgramme)(PROGRAMMES.find((known) => known === value) ?? '');
                    }}
                    emptyLabel={words.allProgrammes}
                    options={PROGRAMMES.map((value) => ({ value, label: value }))}
                />
            ) : null}
        </>
    );

    return (
        <Page title={words.title} lede={words.description}>
            <Panel
                title={words.listTitle}
                icon={<ChartCandlestick className="size-4" />}
                flush
                foot={
                    answer === null ? undefined : (
                        <Pagination
                            page={page}
                            pageSize={pageSize}
                            total={answer.total}
                            unit={words.unit}
                            onPageChange={setPage}
                            onPageSizeChange={setPageSize}
                        />
                    )
                }
            >
                <ToolbarRows>
                    <ToolbarRow>
                        <SearchInput
                            value={search}
                            placeholder={words.search}
                            onChange={(value) => {
                                setSearch(value);
                                setPage(1);
                            }}
                        />
                    </ToolbarRow>
                    <ToolbarRow divided {...(wide && clear !== undefined ? { end: clear } : {})}>
                        {/* The kinds as tabs that count what each would show; on a phone, a select saying the same. */}
                        {wide ? (
                            <Tabs
                                value={kind === '' ? 'ALL' : kind}
                                onChange={(value) => {
                                    narrowed(setKind)(value === 'ALL' ? '' : value);
                                }}
                                options={[
                                    { value: 'ALL' as const, label: <KindLabel label={words.all} count={total} /> },
                                    ...INSTRUMENT_KINDS.map((value) => {
                                        const look = INSTRUMENT_KIND_LOOK[value];

                                        return {
                                            value,
                                            icon: (
                                                <span
                                                    className={cn(
                                                        'tone grid size-5 place-items-center rounded-md',
                                                        look.tone,
                                                    )}
                                                >
                                                    <look.icon className="size-3" aria-hidden="true" />
                                                </span>
                                            ),
                                            label: (
                                                <KindLabel
                                                    label={words.kindsPlural[value]}
                                                    count={answer?.counts[value] ?? null}
                                                />
                                            ),
                                        };
                                    }),
                                ]}
                            />
                        ) : (
                            <PanelSelect
                                className="filter-select"
                                aria-label={words.kind}
                                value={kind}
                                onValueChange={(value) => {
                                    narrowed(setKind)(INSTRUMENT_KINDS.find((known) => known === value) ?? '');
                                }}
                                emptyLabel={counted(words.all, total)}
                                options={INSTRUMENT_KINDS.map((value) => ({
                                    value,
                                    label: counted(words.kindsPlural[value], answer?.counts[value] ?? null),
                                }))}
                            />
                        )}
                        {selects}
                        {wide ? null : clear}
                    </ToolbarRow>
                </ToolbarRows>

                {answer === null ? (
                    failed ? (
                        <p className="note p-4">{words.failed}</p>
                    ) : (
                        <TableSkeleton />
                    )
                ) : answer.data.length === 0 ? (
                    <EmptyState icon={<SearchX className="size-5" />} title={words.none} />
                ) : (
                    <DataTable
                        stacked
                        head={
                            <>
                                <th>{words.columns.name}</th>
                                <th>{words.columns.kind}</th>
                                <th className="col-figure">{words.columns.rate}</th>
                                <th>{words.columns.maturity}</th>
                                <th className="col-figure">{words.columns.price}</th>
                            </>
                        }
                    >
                        {answer.data.map((instrument) => (
                            <InstrumentRow key={instrument.id} instrument={instrument} />
                        ))}
                    </DataTable>
                )}
            </Panel>
        </Page>
    );
}

/** A kind in the phone's select: its name and, once counted, how many the other filters keep of it. */
function counted(label: string, count: number | null): string {
    return count === null ? label : `${label} (${String(count)})`;
}

/** A kind's tab: its name and, once counted, how many the other filters keep of it. */
function KindLabel({ label, count }: { label: string; count: number | null }): JSX.Element {
    return (
        <>
            {label}
            {count === null ? null : <span className="text-muted-foreground tabular-nums">{count}</span>}
        </>
    );
}

/** One instrument: its name and codes, its kind, rate, maturity and latest price. */
/**
 * The issuer's mark beside its name: the logo cached from its BVB page when there is one, otherwise a
 * small initials tile. The logo loads lazily and falls back to the initials if the image fails, so a
 * row never shows a broken image.
 */
function IssuerMark({ instrument }: { instrument: Instrument }): JSX.Element {
    const [failed, setFailed] = useState(false);
    const initials =
        (instrument.symbol ?? instrument.issuer)
            .replace(/[^A-Za-z0-9]/g, '')
            .slice(0, 2)
            .toUpperCase() || '—';

    if (instrument.logoUrl !== null && !failed) {
        return (
            <span className="market-issuer-mark">
                <img
                    src={instrument.logoUrl}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    onError={() => {
                        setFailed(true);
                    }}
                />
            </span>
        );
    }

    return (
        <span className="market-issuer-mark market-issuer-mark-fallback" aria-hidden="true">
            {initials}
        </span>
    );
}

function InstrumentRow({ instrument }: { instrument: Instrument }): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.market);
    const look = INSTRUMENT_KIND_LOOK[instrument.kind];
    const codes = [instrument.symbol ?? instrument.issueCode, instrument.isin].filter((code) => code !== null);

    return (
        <tr>
            <td className="col-primary">
                <div className="market-issuer">
                    <IssuerMark instrument={instrument} />
                    <div className="market-issuer-text">
                        <Link href={`/investments/${instrument.id}`} className="font-semibold">
                            {instrument.name}
                        </Link>
                        <div className="note">
                            {codes.length === 0 ? null : <span className="mono">{codes.join(' · ')}</span>}
                            {codes.length === 0 ? '' : ' · '}
                            {instrument.issuer}
                        </div>
                    </div>
                </div>
            </td>
            <td>
                <CellLabel icon={<look.icon className="size-3.5" aria-hidden="true" />}>{words.columns.kind}</CellLabel>
                <span className="flex flex-wrap gap-1.5">
                    <Pill className={cn('tone', look.tone)}>
                        <look.icon aria-hidden="true" />
                        {words.kinds[instrument.kind]}
                    </Pill>
                    {instrument.status === 'ON_OFFER' ? (
                        <Pill className="bg-success/10 text-success">{words.statuses.ON_OFFER}</Pill>
                    ) : null}
                </span>
            </td>
            <td className="col-figure">
                {instrument.ratePercent === null ? null : (
                    <>
                        <CellLabel icon={<Percent className="size-3.5" aria-hidden="true" />}>
                            {words.columns.rate}
                        </CellLabel>
                        {`${instrument.ratePercent}%`}
                    </>
                )}
            </td>
            <td className="whitespace-nowrap">
                {instrument.maturityDate === null ? null : (
                    <>
                        <CellLabel icon={<CalendarCheck className="size-3.5" aria-hidden="true" />}>
                            {words.columns.maturity}
                        </CellLabel>
                        <span>{formatDate(instrument.maturityDate, locale)}</span>
                        <span className="note block">
                            {instrument.status === 'MATURED'
                                ? words.statuses.MATURED
                                : instrument.maturesIn === null
                                  ? null
                                  : spanFromNow(instrument.maturesIn, locale)}
                        </span>
                    </>
                )}
            </td>
            <td className="col-figure">
                {instrument.latestPrice === null ? null : (
                    <>
                        <CellLabel icon={<Coins className="size-3.5" aria-hidden="true" />}>
                            {words.columns.price}
                        </CellLabel>
                        <span>
                            {instrument.kind === 'SHARE'
                                ? `${instrument.latestPrice.price} ${instrument.currency}`
                                : words.ofFace.replace('{price}', instrument.latestPrice.price)}
                        </span>
                        {instrument.latestPrice.tradingDate === null ? null : (
                            <span className="note block">
                                {words.priceOn.replace(
                                    '{date}',
                                    formatDate(instrument.latestPrice.tradingDate, locale),
                                )}
                            </span>
                        )}
                    </>
                )}
            </td>
        </tr>
    );
}
