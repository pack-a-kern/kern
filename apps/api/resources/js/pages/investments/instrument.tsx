import { Link } from '@inertiajs/react';
import type { ChartConfiguration } from 'chart.js';
import {
    ArrowLeft,
    ArrowLeftRight,
    Banknote,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarPlus,
    CircleDollarSign,
    Fingerprint,
    Gauge,
    Hash,
    Landmark,
    Layers,
    CalendarRange,
    ChartLine,
    Coins,
    Gavel,
    Percent,
    RefreshCw,
    Scale,
    TrendingDown,
    Tag,
    TrendingUp,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type JSX, type ReactNode } from 'react';
import { toast } from 'sonner';

import {
    getInstrument,
    getIssuerProfile,
    getPrices,
    listInstrumentPayouts,
    refreshIssuerProfile,
} from '../../api/investmentsApi';
import { Chart } from '../../components/Chart';
import {
    CellLabel,
    DataTable,
    EmptyState,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelSelect,
    Pill,
    Stat,
    StatGrid,
    TableSkeleton,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useRememberedState } from '../../hooks/useRememberedState';
import { useTranslation } from '../../i18n';
import { hoverLine } from '../../lib/chartHoverLine';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate, formatDateTime, formatMinor, formatNumber } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { INSTRUMENT_KIND_LOOK } from '../../lib/instrumentKinds';
import { themeColor } from '../../lib/themeColor';
import { spanFromNow } from '../../lib/spanFromNow';
import { cn } from '../../lib/utils';
import type {
    InstrumentDetail,
    IssuerFigures,
    IssuerProfile,
    Payout,
    PayoutRow,
    PricePoint,
} from '../../types/investments';

/** The ranges the price chart offers, in months — within `INVESTMENTS_API_MAX_SPAN_DAYS`. */
const WINDOWS = ['1', '3', '12', '60'] as const;
type Window = (typeof WINDOWS)[number];

/**
 * One instrument (ADR 0025 §11), in two cards. The first is the instrument itself: the way back to the
 * market, its name, its payments behind one button, then what it is — kind, status, programme, market —
 * the four figures a reader looks for, and its terms, with the order a government bond was read from.
 * The second is its price over a range the reader picks, as a line. Read-only: a holding of it is added
 * from My holdings.
 */
export default function InstrumentPage({ instrumentId }: { instrumentId: string }): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.instrument);
    const market = t((d) => d.pages.market);
    const [instrument, setInstrument] = useState<InstrumentDetail | null>(null);
    const [payoutsOpen, setPayoutsOpen] = useState(false);
    // A share or bond listed on BVB has a page there to read; a Tezaur bond, never listed, has none.
    const issuer = useIssuerProfile(instrument?.market === 'BVB' ? instrument.symbol : null);
    const figures = issuer.profile?.figures ?? null;

    useEffect(() => {
        let current = true;

        getInstrument(instrumentId)
            .then((found) => {
                if (current) {
                    setInstrument(found);
                }
            })
            .catch(() => {
                if (current) {
                    toast.error(words.failed);
                }
            });

        return () => {
            current = false;
        };
    }, [instrumentId, words.failed]);

    const day = (iso: string): string => formatDate(iso, locale);

    return (
        <Page title={market.title} lede={market.description}>
            {instrument === null ? (
                <Skeleton className="tile h-72 w-full" />
            ) : (
                <>
                    <Panel
                        icon={
                            <Link
                                href="/investments"
                                className="grid size-full place-items-center"
                                aria-label={words.back}
                            >
                                <ArrowLeft className="size-4" aria-hidden="true" />
                            </Link>
                        }
                        title={instrument.name}
                        description={instrument.issuer}
                        aside={
                            instrument.payoutsCount === 0 ? undefined : (
                                <PanelButton
                                    onClick={() => {
                                        setPayoutsOpen(true);
                                    }}
                                >
                                    <CalendarClock className="size-3.5" aria-hidden="true" />
                                    {words.showPayouts.replace('{count}', String(instrument.payoutsCount))}
                                </PanelButton>
                            )
                        }
                    >
                        <div className="space-y-4">
                            <Badges instrument={instrument} figures={figures} />
                            <Figures instrument={instrument} figures={figures} />
                            <Facts
                                items={[
                                    instrument.isin === null
                                        ? null
                                        : { icon: Fingerprint, label: words.isin, value: instrument.isin, mono: true },
                                    instrument.symbol === null
                                        ? null
                                        : { icon: Tag, label: words.symbol, value: instrument.symbol, mono: true },
                                    instrument.issueCode === null
                                        ? null
                                        : { icon: Hash, label: words.code, value: instrument.issueCode, mono: true },
                                    { icon: CircleDollarSign, label: words.currency, value: instrument.currency },
                                    figures?.sharesTotal == null
                                        ? null
                                        : {
                                              icon: Layers,
                                              label: words.sharesTotal,
                                              value: formatNumber(figures.sharesTotal, locale),
                                          },
                                    // A bond's from its terms; a share's, and a listed bond's if its terms lack it, from BVB.
                                    (instrument.faceValue ?? figures?.faceValue ?? null) === null
                                        ? null
                                        : {
                                              icon: Banknote,
                                              label: words.faceValue,
                                              value: `${String(instrument.faceValue ?? figures?.faceValue)} ${instrument.currency}`,
                                          },
                                    figures?.unitsIssued == null
                                        ? null
                                        : {
                                              icon: Layers,
                                              label: words.unitsIssued,
                                              value: formatNumber(figures.unitsIssued, locale),
                                          },
                                    figures?.issueValue == null
                                        ? null
                                        : {
                                              icon: Landmark,
                                              label: words.issueValue,
                                              value: money(figures.issueValue, instrument.currency, locale),
                                          },
                                    figures?.bid == null || figures.ask === null
                                        ? null
                                        : {
                                              icon: ArrowLeftRight,
                                              label: words.bidAsk,
                                              value: words.bidAskValue
                                                  .replace('{bid}', figures.bid)
                                                  .replace('{ask}', figures.ask),
                                          },
                                    figures?.shareCapital == null
                                        ? null
                                        : {
                                              icon: Landmark,
                                              label: words.shareCapital,
                                              value: money(figures.shareCapital, instrument.currency, locale),
                                          },
                                    figures?.tradingSince == null
                                        ? null
                                        : {
                                              icon: CalendarCheck,
                                              label: words.tradingSince,
                                              value: day(figures.tradingSince),
                                          },
                                    instrument.issueDate === null
                                        ? null
                                        : { icon: CalendarPlus, label: words.issued, value: day(instrument.issueDate) },
                                    instrument.subscribedValueMinor === null || instrument.subscriptions === null
                                        ? null
                                        : {
                                              icon: Users,
                                              label: words.subscriptions,
                                              value: words.subscribed
                                                  .replace(
                                                      '{value}',
                                                      formatMinor(
                                                          instrument.subscribedValueMinor,
                                                          instrument.currency,
                                                          locale,
                                                      ),
                                                  )
                                                  .replace('{count}', formatNumber(instrument.subscriptions, locale)),
                                          },
                                    instrument.termsDocumentId === null
                                        ? null
                                        : {
                                              icon: Gavel,
                                              label: words.order,
                                              value: (
                                                  <Link
                                                      href={`/legislation/${instrument.termsDocumentId}`}
                                                      className="text-info"
                                                  >
                                                      {words.openOrder}
                                                  </Link>
                                              ),
                                          },
                                ]}
                            />
                        </div>
                    </Panel>

                    <PriceHistory instrument={instrument} />

                    {instrument.market === 'BVB' && instrument.symbol !== null ? (
                        <IssuerProfileCard
                            bond={instrument.kind !== 'SHARE'}
                            profile={issuer.profile}
                            reading={issuer.reading}
                            onRead={() => void issuer.read()}
                        />
                    ) : null}

                    <Payouts instrument={instrument} open={payoutsOpen} onOpenChange={setPayoutsOpen} />
                </>
            )}
        </Page>
    );
}

/**
 * What it is, at a glance: its kind in its colour, whether it is on offer or past its maturity, where it
 * trades, and — for a share whose issuer was read — its segment and category on BVB.
 */
function Badges({ instrument, figures }: { instrument: InstrumentDetail; figures: IssuerFigures | null }): JSX.Element {
    const { t } = useTranslation();
    const market = t((d) => d.pages.market);
    const look = INSTRUMENT_KIND_LOOK[instrument.kind];

    return (
        <div className="flex flex-wrap gap-1.5">
            <Pill className={cn('tone', look.tone)}>
                <look.icon aria-hidden="true" />
                {market.kinds[instrument.kind]}
            </Pill>
            <Pill
                className={cn(
                    instrument.status === 'ON_OFFER' && 'bg-success/10 text-success',
                    instrument.status === 'DELISTED' && 'bg-warning/15 text-warning',
                )}
            >
                {market.statuses[instrument.status]}
            </Pill>
            {instrument.programme === null ? null : <Pill tone="accent">{instrument.programme}</Pill>}
            {instrument.market === null ? null : <Pill tone="locked">{instrument.market}</Pill>}
            {figures?.segment == null ? null : <Pill>{figures.segment}</Pill>}
            {figures?.category == null ? null : <Pill tone="accent">{figures.category}</Pill>}
        </div>
    );
}

/**
 * The figures that mean something for what it is. A share: its latest price and move, and — once its issuer
 * was read from BVB — its capitalisation, P/E and P/BV, dividend yield, EPS and 52-week range; its dividends,
 * the last paid and the next announced. A bond: its price where it trades, its rate — and the coupon BVB
 * shows, where that differs — its maturity, its next payment, and once read from BVB its yield to maturity
 * and 52-week range, in percent of its face value. A tile with nothing to say is not drawn.
 */
function Figures({
    instrument,
    figures,
}: {
    instrument: InstrumentDetail;
    figures: IssuerFigures | null;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.instrument);
    const market = t((d) => d.pages.market);
    const day = (iso: string): string => formatDate(iso, locale);
    const change = instrument.latestChange;
    const price = instrument.latestPrice;
    const share = instrument.kind === 'SHARE';
    const next = instrument.nextPayout;
    const last = instrument.lastPayout;
    const amount = (payout: Payout): string =>
        payout.amountPerUnit === null
            ? payout.ratePercent === null
                ? '—'
                : rate(payout.ratePercent, locale)
            : unitAmount(payout.amountPerUnit, instrument.currency, locale);
    const range =
        figures?.week52Low == null || figures.week52High === null ? null : (
            <Stat
                label={words.range52}
                icon={<ChartLine className="size-3.5" aria-hidden="true" />}
                {...(share
                    ? { value: `${figures.week52Low} – ${figures.week52High}`, sub: instrument.currency }
                    : { value: `${figures.week52Low}% – ${figures.week52High}%` })}
            />
        );
    // The coupon BVB shows, said only where the server found it is not the rate the terms give.
    const bvbCoupon =
        figures?.couponDiffers === true && figures.couponRate !== null
            ? words.bvbCoupon.replace('{value}', figures.couponRate)
            : null;

    return (
        <StatGrid className="stat-grid-thirds">
            {price === null && share ? null : (
                <Stat
                    label={words.price}
                    icon={
                        change?.trend === 'DOWN' ? (
                            <TrendingDown className="size-3.5" aria-hidden="true" />
                        ) : (
                            <TrendingUp className="size-3.5" aria-hidden="true" />
                        )
                    }
                    tone={
                        change === null || change.trend === 'FLAT'
                            ? 'neutral'
                            : change.trend === 'UP'
                              ? 'success'
                              : 'destructive'
                    }
                    value={price === null ? '—' : share ? `${price.price} ${instrument.currency}` : `${price.price}%`}
                    {...(price === null
                        ? { sub: words.notTraded }
                        : {
                              sub: [
                                  change === null ? null : `${change.percent}%`,
                                  price.tradingDate === null
                                      ? null
                                      : words.priceOn.replace('{date}', day(price.tradingDate)),
                              ]
                                  .filter((part) => part !== null)
                                  .join(' · '),
                          })}
                />
            )}
            {share ? (
                <>
                    {figures?.marketCap == null ? null : (
                        <Stat
                            label={words.marketCap}
                            icon={<Building2 className="size-3.5" aria-hidden="true" />}
                            value={money(figures.marketCap, instrument.currency, locale)}
                        />
                    )}
                    {figures?.priceEarnings == null ? null : (
                        <Stat
                            label={words.priceEarnings}
                            icon={<Scale className="size-3.5" aria-hidden="true" />}
                            value={figures.priceEarnings}
                            {...(figures.priceBook === null
                                ? {}
                                : { sub: words.priceBook.replace('{value}', figures.priceBook) })}
                        />
                    )}
                    {figures?.dividendYield == null ? null : (
                        <Stat
                            label={words.dividendYield}
                            icon={<Percent className="size-3.5" aria-hidden="true" />}
                            tone="accent"
                            value={`${figures.dividendYield}%`}
                            {...(figures.lastDividend === null || figures.lastDividendYear === null
                                ? {}
                                : {
                                      sub: words.dividendOf
                                          .replace('{year}', String(figures.lastDividendYear))
                                          .replace('{amount}', `${figures.lastDividend} ${instrument.currency}`),
                                  })}
                        />
                    )}
                    {figures?.earningsPerShare == null ? null : (
                        <Stat
                            label={words.earningsPerShare}
                            icon={<Coins className="size-3.5" aria-hidden="true" />}
                            value={`${figures.earningsPerShare} ${instrument.currency}`}
                        />
                    )}
                    {range}
                    {/* The yield tile says the last dividend once the issuer is read; until then, this does. */}
                    {last === null || figures?.dividendYield != null ? null : (
                        <Stat
                            label={words.lastDividend}
                            icon={<Coins className="size-3.5" aria-hidden="true" />}
                            value={amount(last)}
                            sub={words.paidOn.replace('{date}', day(last.paymentDate))}
                        />
                    )}
                    {next === null ? null : (
                        <Stat
                            label={words.nextDividend}
                            icon={<CalendarClock className="size-3.5" aria-hidden="true" />}
                            tone="accent"
                            value={amount(next)}
                            sub={
                                next.exDate === null
                                    ? words.paysOn.replace('{date}', day(next.paymentDate))
                                    : words.exOn.replace('{date}', day(next.exDate))
                            }
                        />
                    )}
                </>
            ) : (
                <>
                    {instrument.ratePercent === null ? null : (
                        <Stat
                            label={words.rate}
                            icon={<Percent className="size-3.5" aria-hidden="true" />}
                            tone="accent"
                            value={`${instrument.ratePercent}%`}
                            {...(bvbCoupon === null ? {} : { sub: bvbCoupon })}
                        />
                    )}
                    {figures?.yieldToMaturity == null ? null : (
                        <Stat
                            label={words.yieldToMaturity}
                            icon={<Gauge className="size-3.5" aria-hidden="true" />}
                            value={`${figures.yieldToMaturity}%`}
                            sub={words.atCurrentPrice}
                        />
                    )}
                    {instrument.maturityDate === null ? null : (
                        <Stat
                            label={words.matures}
                            icon={<CalendarCheck className="size-3.5" aria-hidden="true" />}
                            value={day(instrument.maturityDate)}
                            {...(instrument.status === 'MATURED'
                                ? { sub: market.statuses.MATURED }
                                : instrument.maturesIn === null
                                  ? {}
                                  : { sub: spanFromNow(instrument.maturesIn, locale) })}
                        />
                    )}
                    {next === null ? null : (
                        <Stat
                            label={words.nextPayment}
                            icon={<CalendarClock className="size-3.5" aria-hidden="true" />}
                            value={day(next.paymentDate)}
                            sub={`${words.payoutKinds[next.kind]} · ${amount(next)}`}
                        />
                    )}
                    {range}
                </>
            )}
        </StatGrid>
    );
}

/**
 * Its terms as a grid of facts — a mark, a label, a value — one column on a phone, the value at its far
 * edge; two, then three as the card widens, the value under its label; each set off from the next by a
 * hairline. A fact it does not have is left out.
 */
function Facts({
    items,
}: {
    items: ({ icon: LucideIcon; label: string; value: ReactNode; mono?: boolean } | null)[];
}): JSX.Element {
    return (
        <dl className="facts">
            {items
                .filter((item) => item !== null)
                .map((item) => (
                    <div key={item.label} className="fact">
                        <span className="fact-mark">
                            <item.icon className="size-3.5" aria-hidden="true" />
                        </span>
                        <div className="fact-text">
                            <dt className="note">{item.label}</dt>
                            <dd className={cn('fact-figure', item.mono === true && 'mono')}>{item.value}</dd>
                        </div>
                    </div>
                ))}
        </dl>
    );
}

/** Its price over a range the reader picks — remembered — as a line, the latest point marked. */
function PriceHistory({ instrument }: { instrument: InstrumentDetail }): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.instrument);
    const [months, setMonths] = useRememberedState<Window>('investments.instrument.window', '3', {
        isValid: (value) => WINDOWS.includes(value),
    });
    // Kept with the range it answers: while another range loads, the chart waits rather than show the last.
    const [loaded, setLoaded] = useState<{ months: Window; points: PricePoint[] } | null>(null);
    const points = loaded?.months === months ? loaded.points : null;
    const share = instrument.kind === 'SHARE';

    useEffect(() => {
        let current = true;

        getPrices(instrument.id, Number(months))
            .then((answer) => {
                if (current) {
                    setLoaded({ months, points: answer });
                }
            })
            .catch(() => {
                if (current) {
                    setLoaded({ months, points: [] });
                    toast.error(words.failed);
                }
            });

        return () => {
            current = false;
        };
    }, [instrument.id, months, words.failed]);

    const config = useMemo<ChartConfiguration>(() => {
        const rows = points ?? [];
        // A share's price in its currency; a bond's in percent of its face value.
        const shown = (value: number): string =>
            share ? `${value.toFixed(2)} ${instrument.currency}` : `${value.toFixed(2)}%`;
        const axisDates = rows.map((point) =>
            new Date(`${point.tradingDate}T00:00:00Z`).toLocaleDateString(locale, {
                day: 'numeric',
                month: 'short',
                timeZone: 'UTC',
            }),
        );

        return {
            type: 'line',
            data: {
                labels: rows.map((point) => point.tradingDate),
                datasets: [
                    {
                        data: rows.map((point) => Number(point.price)),
                        borderColor: themeColor('--info'),
                        backgroundColor: themeColor('--info', 0.12),
                        borderWidth: 2,
                        fill: true,
                        pointRadius: rows.map((_, index, all) => (index === all.length - 1 ? 3 : 0)),
                        pointBackgroundColor: themeColor('--info'),
                        pointHoverRadius: 4,
                        tension: 0.3,
                    },
                ],
            },
            plugins: [hoverLine],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false, axis: 'x' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: (items) => formatDate(items[0]?.label ?? '', locale),
                            label: (item) => shown(item.parsed.y ?? 0),
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: themeColor('--muted-foreground'),
                            font: { size: 10 },
                            autoSkip: true,
                            maxTicksLimit: 6,
                            maxRotation: 0,
                            callback: (_, index) => axisDates.at(index) ?? '',
                        },
                    },
                    y: {
                        // Not from zero: a price moving between 98 and 101 is a flat line above a zero axis.
                        beginAtZero: false,
                        border: { display: false },
                        grid: { color: themeColor('--border') },
                        ticks: {
                            color: themeColor('--muted-foreground'),
                            font: { size: 10 },
                            maxTicksLimit: 5,
                            callback: (value) => Number(value).toFixed(2),
                        },
                    },
                },
            },
        };
    }, [points, locale, share, instrument.currency]);

    return (
        <Panel
            title={words.prices}
            icon={<ChartLine className="size-4" />}
            aside={
                <PanelSelect
                    className="ctl-narrow"
                    aria-label={words.window}
                    value={months}
                    onValueChange={(value) => {
                        setMonths(WINDOWS.find((known) => known === value) ?? '3');
                    }}
                    options={WINDOWS.map((value) => ({ value, label: words.windows[value] }))}
                />
            }
        >
            {points === null ? (
                <Skeleton className="h-48 w-full" />
            ) : points.length < 2 ? (
                <EmptyState icon={<ChartLine className="size-5" />} title={words.noPrices} />
            ) : (
                <div className="chart-line-sm">
                    <Chart config={config} label={words.prices} />
                </div>
            )}
        </Panel>
    );
}

/**
 * A listed instrument's profile, as last read from BVB — undefined while it loads, null when never read, and
 * none at all for anything else — with the reading the reader asks for: the one call on this page that
 * reaches out.
 */
function useIssuerProfile(symbol: string | null): {
    profile: IssuerProfile | null | undefined;
    reading: boolean;
    read: () => Promise<void>;
} {
    const { t } = useTranslation();
    const tooOften = t((d) => d.pages.instrument.profile.tooOften);
    const [profile, setProfile] = useState<IssuerProfile | null | undefined>(undefined);
    const [reading, setReading] = useState(false);

    useEffect(() => {
        if (symbol === null) {
            return;
        }

        let current = true;

        getIssuerProfile(symbol)
            .then((found) => {
                if (current) {
                    setProfile(found);
                }
            })
            .catch((cause: unknown) => {
                if (current) {
                    setProfile(null);
                    toast.error(describeApiFailure(cause, t));
                }
            });

        return () => {
            current = false;
        };
    }, [symbol, t]);

    const read = async (): Promise<void> => {
        if (symbol === null) {
            return;
        }

        setReading(true);

        try {
            setProfile(await refreshIssuerProfile(symbol));
        } catch (cause) {
            toast.error(cause instanceof ApiError && cause.isRateLimited ? tooOften : describeApiFailure(cause, t));
        } finally {
            setReading(false);
        }
    };

    return { profile: symbol === null ? null : profile, reading, read };
}

/**
 * The company behind a share, as BVB last showed it: its main shareholders, each a bar of its percent, its
 * shareholders' meetings and its financial calendar — and the button that reads them again. A bond's card
 * has no issuer to show: its financial calendar alone, its figures being in the first card.
 */
function IssuerProfileCard({
    bond,
    profile,
    reading,
    onRead,
}: {
    bond: boolean;
    profile: IssuerProfile | null | undefined;
    reading: boolean;
    onRead: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.instrument.profile);

    return (
        <Panel
            title={bond ? words.titleBond : words.title}
            icon={<Building2 className="size-4" />}
            aside={
                <PanelButton disabled={reading || profile === undefined} onClick={onRead}>
                    <RefreshCw className={cn('size-3.5', reading && 'animate-spin')} aria-hidden="true" />
                    {words.refresh}
                </PanelButton>
            }
            {...(profile === null || profile === undefined
                ? {}
                : { footNote: words.readAt.replace('{date}', formatDateTime(profile.readAt, locale)) })}
        >
            {profile === undefined ? (
                <Skeleton className="h-32 w-full" />
            ) : profile === null ? (
                <EmptyState
                    icon={<Building2 className="size-5" />}
                    title={bond ? words.neverBond : words.never}
                    description={bond ? words.neverHintBond : words.neverHint}
                />
            ) : bond ? (
                <DatedList
                    title={words.financialCalendar}
                    empty={words.noFinancialEvents}
                    items={profile.financialCalendar}
                />
            ) : (
                <div className="grid gap-6 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <section className="space-y-3">
                        <h3 className="eyebrow flex items-center gap-1.5">
                            <Users className="size-3.5" aria-hidden="true" />
                            {profile.shareholdersAsOf === null
                                ? words.shareholders
                                : words.shareholdersAsOf.replace(
                                      '{date}',
                                      formatDate(profile.shareholdersAsOf, locale),
                                  )}
                        </h3>
                        {profile.shareholders.length === 0 ? (
                            <p className="note">{words.noShareholders}</p>
                        ) : (
                            <ul className="space-y-2.5">
                                {profile.shareholders.map((holder) => (
                                    <li key={holder.holder} className="space-y-1">
                                        <div className="flex items-baseline justify-between gap-3 text-xs">
                                            {/* A holder's whole name, over two lines at most: BVB writes some with their address. */}
                                            <span
                                                className="min-w-0 line-clamp-2 font-medium break-words"
                                                title={holder.holder}
                                            >
                                                {holder.holder}
                                            </span>
                                            <span className="shrink-0 font-semibold tabular-nums">{`${holder.percent}%`}</span>
                                        </div>
                                        <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                                            <div
                                                className="tone-violet h-full rounded-full bg-(--tone)"
                                                style={{ width: `${String(Math.min(100, Number(holder.percent)))}%` }}
                                            />
                                        </div>
                                        <div className="note tabular-nums">
                                            {words.shares.replace('{count}', formatNumber(holder.shares, locale))}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <div className="space-y-6">
                        <DatedList
                            title={words.meetings}
                            empty={words.noMeetings}
                            items={profile.meetings.map((meeting) => ({
                                date: meeting.date,
                                what: meeting.kind,
                                upcoming: meeting.upcoming,
                            }))}
                        />
                        <DatedList
                            title={words.financialCalendar}
                            empty={words.noFinancialEvents}
                            items={profile.financialCalendar}
                        />
                    </div>
                </div>
            )}
        </Panel>
    );
}

/** Days on an issuer's calendar, in the server's order — latest first — the ones it says are still to come marked. */
function DatedList({
    title,
    empty,
    items,
}: {
    title: string;
    empty: string;
    items: { date: string; what: string; upcoming: boolean }[];
}): JSX.Element {
    const { t, locale } = useTranslation();
    const upcoming = t((d) => d.pages.instrument.profile.upcoming);

    return (
        <section className="space-y-3">
            <h3 className="eyebrow flex items-center gap-1.5">
                <CalendarDays className="size-3.5" aria-hidden="true" />
                {title}
            </h3>
            {items.length === 0 ? (
                <p className="note">{empty}</p>
            ) : (
                <ul className="space-y-3">
                    {items.map((item) => (
                        // The day and whether it is still to come on one line, what happens under it: a long
                        // name never squeezes the date, nor the date the name.
                        <li
                            key={`${item.date}-${item.what}`}
                            className={cn('border-l-2 pl-3 text-xs', item.upcoming ? 'border-info' : 'border-border')}
                        >
                            <div className="flex flex-wrap items-center gap-1.5 font-semibold tabular-nums">
                                {formatDate(item.date, locale)}
                                {item.upcoming ? <Pill tone="accent">{upcoming}</Pill> : null}
                            </div>
                            <p className="text-muted-foreground mt-0.5 break-words">{item.what}</p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Its payments, in a dialog, asked for from the server when it opens: a bond's coupons as its terms lay
 * them out — each numbered, its rate, its period, the record day that says who is paid, the day it pays —
 * and a share's dividends, whether each was paid, its period where it has one, the day it pays and what it
 * pays per share.
 */
function Payouts({
    instrument,
    open,
    onOpenChange,
}: {
    instrument: InstrumentDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.instrument);
    const date = t((d) => d.pages.walletTransactions.columns.date);
    const [rows, setRows] = useState<PayoutRow[] | null>(null);
    const day = (iso: string | null): string => (iso === null ? '' : formatDate(iso, locale));
    const period = (payout: Payout): string =>
        payout.periodStart === null || payout.periodEnd === null
            ? ''
            : `${day(payout.periodStart)} – ${day(payout.periodEnd)}`;
    const cell = (icon: LucideIcon, label: string, value: string, className = 'whitespace-nowrap'): JSX.Element => {
        const Icon = icon;

        return (
            <td className={className}>
                {value === '' ? null : (
                    <>
                        <CellLabel icon={<Icon className="size-3.5" aria-hidden="true" />}>{label}</CellLabel>
                        {value}
                    </>
                )}
            </td>
        );
    };

    useEffect(() => {
        if (!open) {
            return;
        }

        let current = true;

        listInstrumentPayouts(instrument.id)
            .then((answer) => {
                if (current) {
                    setRows(answer);
                }
            })
            .catch(() => {
                if (current) {
                    setRows([]);
                    toast.error(words.failed);
                }
            });

        return () => {
            current = false;
        };
    }, [open, instrument.id, words.failed]);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={words.payouts}
            description={instrument.name}
            size="lg"
            dismissOnOutsideClick
            actions={
                <PanelButton
                    onClick={() => {
                        onOpenChange(false);
                    }}
                >
                    <X className="size-3.5" aria-hidden="true" />
                    {words.close}
                </PanelButton>
            }
        >
            {rows === null ? (
                <TableSkeleton />
            ) : instrument.kind === 'SHARE' ? (
                <DataTable
                    stacked
                    head={
                        <>
                            <th>{words.payouts}</th>
                            <th>{words.period}</th>
                            <th>{date}</th>
                            <th className="col-figure">{words.perUnit}</th>
                        </>
                    }
                >
                    {rows.map((payout) => (
                        <tr key={payout.id}>
                            <td className="col-primary">
                                <span className="flex flex-wrap items-center gap-1.5">
                                    {words.payoutKinds[payout.kind]}
                                    <Pill className={cn(payout.status !== 'PAID' && 'bg-info/10 text-info')}>
                                        {payout.status === 'PAID' ? words.paid : words.announced}
                                    </Pill>
                                </span>
                            </td>
                            {cell(CalendarRange, words.period, period(payout))}
                            {cell(CalendarCheck, date, day(payout.paymentDate))}
                            {cell(
                                Coins,
                                words.perUnit,
                                payout.amountPerUnit === null
                                    ? ''
                                    : unitAmount(payout.amountPerUnit, instrument.currency, locale),
                                'col-figure',
                            )}
                        </tr>
                    ))}
                </DataTable>
            ) : (
                <DataTable
                    stacked
                    head={
                        <>
                            <th>{words.couponNumber}</th>
                            <th className="col-figure">{words.couponRate}</th>
                            <th>{words.period}</th>
                            <th>{words.recordDate}</th>
                            <th>{words.paymentDate}</th>
                        </>
                    }
                >
                    {rows.map((payout) => (
                        <tr key={payout.id}>
                            <td className="col-primary tabular-nums">{payout.number}</td>
                            {cell(
                                Percent,
                                words.couponRate,
                                payout.ratePercent === null ? '' : rate(payout.ratePercent, locale),
                                'col-figure',
                            )}
                            {cell(CalendarRange, words.period, period(payout))}
                            {cell(CalendarDays, words.recordDate, day(payout.recordDate))}
                            {cell(CalendarCheck, words.paymentDate, day(payout.paymentDate))}
                        </tr>
                    ))}
                </DataTable>
            )}
        </PanelDialog>
    );
}

/** A rate as a prospectus writes it — "7,50%" — from the plain decimal the server sends. */
function rate(value: string, locale: string): string {
    return `${new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value))}%`;
}

/**
 * What one unit is paid — "7,50 RON", a dividend to every decimal it was declared with ("9,571562 RON") —
 * from the plain decimal the server sends.
 */
function unitAmount(value: string, currency: string, locale: string): string {
    return `${new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 6 }).format(Number(value))} ${currency}`;
}

/** A large figure as a reader takes it in — "84,4 mld. RON" — from the plain decimal the server sends. */
function money(value: string, currency: string, locale: string): string {
    return `${new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value))} ${currency}`;
}
