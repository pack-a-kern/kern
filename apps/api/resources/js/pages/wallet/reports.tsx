import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { ChartConfiguration } from 'chart.js';
import {
    Activity,
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    CalendarRange,
    ChartColumn,
    ChartPie,
    ChevronRight,
    CircleHelp,
    Coins,
    CopyCheck,
    Download,
    Landmark,
    Shapes,
    Tag as TagIcon,
    TrendingUp,
    TriangleAlert,
    Trophy,
    Users,
    WalletCards,
} from 'lucide-react';
import { useEffect, useMemo, useState, type JSX } from 'react';

import { apiRequest } from '../../lib/http';
import AppShell from '../../components/AppShell';
import { DataTable, Page, Panel, PanelButton, PanelSelect, Pill, Stat, StatGrid, Tabs } from '../../components/panel';
import { Chart } from '../../components/Chart';
import { themeColor } from '../../lib/themeColor';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { useRememberedState } from '../../hooks/useRememberedState';
import { formatDate } from '../../lib/format';
import { formatMinor } from '../../types/wallet';

interface TagRow {
    tagId: string;
    name: string;
    currencyCode: string;
    totalMinor: number;
}

interface BankRow {
    bankId: string | null;
    name: string | null;
    currencyCode: string;
    inMinor: number;
    outMinor: number;
}

interface CategoryRow {
    categoryCode: string;
    currencyCode: string;
    totalMinor: number;
}

interface MonthRow {
    month: string;
    currencyCode: string;
    inMinor: number;
    outMinor: number;
    count: number;
    topCategory: string | null;
}

interface LargestRow {
    id: string;
    bookedAt: string;
    counterparty: string | null;
    description: string | null;
    currencyCode: string;
    amountMinor: number;
}

interface Attention {
    unclassifiedCount: number;
    unclassifiedMinor: Array<{ currencyCode: string; totalMinor: number }>;
    duplicateGroups: number;
    totalCount: number;
}

interface Totals {
    currencyCode: string;
    inMinor: number;
    outMinor: number;
    count: number;
}

interface MerchantRow {
    name: string | null;
    currencyCode: string;
    totalMinor: number;
    count: number;
}

interface AccountRow {
    accountId: string;
    name: string | null;
    kind: string;
    currencyCode: string;
    inMinor: number;
    outMinor: number;
}

interface Reports {
    years: number[];
    totals: Totals[];
    previous: Totals[];
    byTag: TagRow[];
    byBank: BankRow[];
    byCategory: CategoryRow[];
    byCounterparty: MerchantRow[];
    byAccount: AccountRow[];
    cashFlow: MonthRow[];
    largest: LargestRow[];
    attention: Attention;
    from: string;
    to: string;
}

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

function str(row: Record<string, unknown>, key: string): string {
    return typeof row[key] === 'string' ? row[key] : '';
}

function num(row: Record<string, unknown>, key: string): number {
    return typeof row[key] === 'number' ? row[key] : 0;
}

function parseTotals(value: unknown): Totals[] {
    return (Array.isArray(value) ? value : []).map((entry): Totals => {
        const row = obj(entry);

        return {
            currencyCode: str(row, 'currency_code'),
            inMinor: num(row, 'in_minor'),
            outMinor: num(row, 'out_minor'),
            count: num(row, 'count'),
        };
    });
}

function parseReports(payload: unknown): Reports {
    const body = obj(payload);

    return {
        byTag: (Array.isArray(body.by_tag) ? body.by_tag : []).map((entry): TagRow => {
            const row = obj(entry);

            return {
                tagId: str(row, 'tag_id'),
                name: str(row, 'name'),
                currencyCode: str(row, 'currency_code'),
                totalMinor: num(row, 'total_minor'),
            };
        }),
        byBank: (Array.isArray(body.by_bank) ? body.by_bank : []).map((entry): BankRow => {
            const row = obj(entry);

            return {
                bankId: typeof row.bank_id === 'string' ? row.bank_id : null,
                name: typeof row.name === 'string' ? row.name : null,
                currencyCode: str(row, 'currency_code'),
                inMinor: num(row, 'in_minor'),
                outMinor: num(row, 'out_minor'),
            };
        }),
        byCategory: (Array.isArray(body.by_category) ? body.by_category : []).map((entry): CategoryRow => {
            const row = obj(entry);

            return {
                categoryCode: str(row, 'category_code'),
                currencyCode: str(row, 'currency_code'),
                totalMinor: num(row, 'total_minor'),
            };
        }),
        cashFlow: (Array.isArray(body.cash_flow) ? body.cash_flow : []).map((entry): MonthRow => {
            const row = obj(entry);

            return {
                month: str(row, 'month'),
                currencyCode: str(row, 'currency_code'),
                inMinor: num(row, 'in_minor'),
                outMinor: num(row, 'out_minor'),
                count: num(row, 'count'),
                topCategory: typeof row.top_category === 'string' ? row.top_category : null,
            };
        }),
        years: (Array.isArray(body.years) ? body.years : []).filter((year): year is number => typeof year === 'number'),
        totals: parseTotals(body.totals),
        previous: parseTotals(body.previous),
        byCounterparty: (Array.isArray(body.by_counterparty) ? body.by_counterparty : []).map((entry): MerchantRow => {
            const row = obj(entry);

            return {
                name: typeof row.name === 'string' ? row.name : null,
                currencyCode: str(row, 'currency_code'),
                totalMinor: num(row, 'total_minor'),
                count: num(row, 'count'),
            };
        }),
        byAccount: (Array.isArray(body.by_account) ? body.by_account : []).map((entry): AccountRow => {
            const row = obj(entry);

            return {
                accountId: str(row, 'account_id'),
                name: typeof row.name === 'string' ? row.name : null,
                kind: str(row, 'kind'),
                currencyCode: str(row, 'currency_code'),
                inMinor: num(row, 'in_minor'),
                outMinor: num(row, 'out_minor'),
            };
        }),
        largest: (Array.isArray(body.largest) ? body.largest : []).map((entry): LargestRow => {
            const row = obj(entry);

            return {
                id: str(row, 'id'),
                bookedAt: str(row, 'booked_at'),
                counterparty: typeof row.counterparty === 'string' ? row.counterparty : null,
                description: typeof row.description === 'string' ? row.description : null,
                currencyCode: str(row, 'currency_code'),
                amountMinor: num(row, 'amount_minor'),
            };
        }),
        attention: ((): Attention => {
            const row = obj(body.attention);

            return {
                unclassifiedCount: num(row, 'unclassified_count'),
                unclassifiedMinor: (Array.isArray(row.unclassified_minor) ? row.unclassified_minor : []).map(
                    (entry) => {
                        const total = obj(entry);

                        return {
                            currencyCode: str(total, 'currency_code'),
                            totalMinor: num(total, 'total_minor'),
                        };
                    },
                ),
                duplicateGroups: num(row, 'duplicate_groups'),
                totalCount: num(row, 'total_count'),
            };
        })(),
        from: str(body, 'from'),
        to: str(body, 'to'),
    };
}

/**
 * The period the report covers, as a year rather than two dates.
 *
 * A report is read a year at a time — that is the unit a return is filed in, and the unit every
 * panel below groups by. Two free dates could express it, but made the common case a pair of
 * decisions and the uncommon case no easier.
 */
function periodRange(period: string): { from: string; to: string } {
    if (period === '12m') {
        const now = new Date();
        const start = new Date(now);

        start.setMonth(start.getMonth() - 11);
        start.setDate(1);

        return { from: start.toISOString().slice(0, 10), to: now.toISOString().slice(0, 10) };
    }

    return { from: `${period}-01-01`, to: `${period}-12-31` };
}

/**
 * A year of transactions, read three ways.
 *
 * ## Why every figure names its currency
 *
 * Because 100 EUR and 100 RON are not 200 of anything. The dashboard converts for display, with the
 * rate and its date beside the figure; a report adds nothing it cannot add honestly, so each row is
 * one currency.
 *
 * ## Not a tax calculation
 *
 * Tags are a person's own labels and categories are what they said their money was. What is owed
 * comes from the tax engine's versioned rules against approved sources — this screen says so, in the
 * panel that could most easily be mistaken for it.
 */
export default function WalletReports(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [reports, setReports] = useState<Reports | null>(null);
    const [failed, setFailed] = useState(false);
    /** A year, or `12m`. Remembered per taxpayer: whoever reads 2025 is still reading it tomorrow. */
    const [period, setPeriod] = useRememberedState<string>('wallet.reports.period', String(new Date().getFullYear()), {
        perSubject: true,
        isValid: (value: string) => value === '12m' || /^\d{4}$/.test(value),
    });
    const { from, to } = periodRange(period);
    /** Which currency the headline figures speak in, when the period holds more than one. */
    const [selected, setSelected] = useState('');
    /** Which question each tabbed panel is answering. Not remembered: they are ways of reading one
        screen, and a tab chosen a month ago is not a preference, it is a stale answer. */
    const [flowMode, setFlowMode] = useState<'all' | 'net'>('all');
    const [breakdownView, setBreakdownView] = useState<'category' | 'tag' | 'merchant'>('category');
    const [movementView, setMovementView] = useState<'bank' | 'currency' | 'account'>('bank');
    const [tableView, setTableView] = useState<'months' | 'largest'>('months');

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;
        const params = new URLSearchParams({ from, to });
        const query = params.toString();

        apiRequest(
            `/v1/subjects/${encodeURIComponent(subjectId)}/wallet/reports${query === '' ? '' : `?${query}`}`,
        ).then(
            (text: string) => {
                if (active) {
                    setReports(parseReports(JSON.parse(text)));
                    setFailed(false);
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
    }, [subjectId, from, to]);

    /**
     * One currency at a time, chosen rather than merged.
     *
     * Every figure here is per currency, because 100 EUR and 100 RON are not 200 of anything. The
     * screen leads with single numbers, so it picks the currency the period holds most movements in
     * and offers the rest beside the period — which is the only honest way to show one headline
     * figure without inventing a conversion nobody asked for.
     */
    const currencies = [...new Set((reports?.totals ?? []).map((row) => row.currencyCode))];
    const currency = currencies.includes(selected) ? selected : (currencies[0] ?? '');

    const totals = reports?.totals.find((row) => row.currencyCode === currency) ?? null;
    const previous = reports?.previous.find((row) => row.currencyCode === currency) ?? null;
    const months = (reports?.cashFlow ?? []).filter((row) => row.currencyCode === currency);

    /** `2026-03` as the month's own name, in the reader's language. */
    const monthLabel = (month: string): string =>
        new Date(`${month}-01T00:00:00Z`).toLocaleDateString(locale, { month: 'short', timeZone: 'UTC' });

    const categoryLabel = (code: string): string => t((d) => d.pages.walletTransactions.categories[code] ?? code);

    /** The change against the same length of time before it, or null when there is nothing to compare. */
    const change = (now: number, before: number): number | null =>
        before === 0 ? null : ((now - before) / before) * 100;

    const money = (minor: number): string => formatMinor(minor, currency, locale);

    /** The widest total in a set, so bars share one scale rather than each filling its row. */
    const widest = (values: number[]): number => Math.max(1, ...values.map((value) => Math.abs(value)));

    const moneyIn = totals?.inMinor ?? 0;
    const moneyOut = totals?.outMinor ?? 0;
    const clean = (reports?.attention.totalCount ?? 0) - (reports?.attention.unclassifiedCount ?? 0);
    const completeness =
        reports === null || reports.attention.totalCount === 0
            ? 100
            : Math.round((clean / reports.attention.totalCount) * 100);

    /** The ranked list behind the breakdown donut, in whichever terms the tab asks for. */
    const breakdown = ((): Array<{ key: string; label: string; totalMinor: number; note?: string }> => {
        if (reports === null) {
            return [];
        }

        if (breakdownView === 'tag') {
            return reports.byTag
                .filter((row) => row.currencyCode === currency)
                .map((row) => ({
                    key: row.tagId,
                    label: row.name === '' ? t((d) => d.pages.walletReports.untagged) : row.name,
                    totalMinor: Math.abs(row.totalMinor),
                }));
        }

        if (breakdownView === 'merchant') {
            return reports.byCounterparty
                .filter((row) => row.currencyCode === currency)
                .map((row, index) => ({
                    key: `${String(index)}-${row.name ?? ''}`,
                    label: row.name ?? t((d) => d.pages.walletReports.noCounterparty),
                    totalMinor: row.totalMinor,
                    note: String(row.count),
                }));
        }

        return reports.byCategory
            .filter((row) => row.currencyCode === currency && row.totalMinor < 0)
            .map((row) => ({
                key: row.categoryCode,
                label: categoryLabel(row.categoryCode),
                totalMinor: Math.abs(row.totalMinor),
            }));
    })().sort((a, b) => b.totalMinor - a.totalMinor);

    const breakdownTotal = breakdown.reduce((sum, row) => sum + row.totalMinor, 0);

    /** The movement table, in whichever terms its tab asks for. */
    const movement = ((): Array<{
        key: string;
        label: string;
        currencyCode: string;
        inMinor: number;
        outMinor: number;
    }> => {
        if (reports === null) {
            return [];
        }

        if (movementView === 'account') {
            return reports.byAccount.map((row) => ({
                key: row.accountId,
                label: row.name ?? t((d) => d.pages.wallet.generalSource),
                currencyCode: row.currencyCode,
                inMinor: row.inMinor,
                outMinor: row.outMinor,
            }));
        }

        if (movementView === 'currency') {
            return reports.totals.map((row) => ({
                key: row.currencyCode,
                label: row.currencyCode,
                currencyCode: row.currencyCode,
                inMinor: row.inMinor,
                outMinor: row.outMinor,
            }));
        }

        return reports.byBank.map((row) => ({
            key: `${row.bankId ?? 'none'}-${row.currencyCode}`,
            label: row.name ?? t((d) => d.pages.walletReports.noBank),
            currencyCode: row.currencyCode,
            inMinor: row.inMinor,
            outMinor: row.outMinor,
        }));
    })();

    /**
     * The five biggest payments and the five biggest receipts, in one table.
     *
     * Ordered by size regardless of direction, because the question the panel answers — what was
     * large enough to move the totals — does not care which way the money went. The sign says that,
     * and it says it in the one place a reader is already looking.
     */
    const largest = [...(reports?.largest ?? [])].sort((a, b) => Math.abs(b.amountMinor) - Math.abs(a.amountMinor));

    /**
     * The year's shape. Two modes, because they answer different questions: the pair of bars says
     * how much moved, the single line says whether the month kept any of it.
     *
     * Rebuilt only when its inputs change — Chart.js is handed this object and keeps it, so a new
     * one on every render would tear the canvas down and redraw it on every keystroke elsewhere.
     */
    const flowConfig = useMemo<ChartConfiguration>(() => {
        const labels = months.map((row) =>
            new Date(`${row.month}-01T00:00:00Z`).toLocaleDateString(locale, {
                month: 'short',
                timeZone: 'UTC',
            }),
        );
        const grid = themeColor('--border');
        const ink = themeColor('--muted-foreground');
        const line = {
            tension: 0.35,
            pointRadius: 2.5,
            pointHoverRadius: 4,
            borderWidth: 2,
        };

        return {
            type: 'line',
            data: {
                labels,
                datasets:
                    flowMode === 'net'
                        ? [
                              {
                                  label: t((d) => d.pages.walletReports.net),
                                  data: months.map((row) => (row.inMinor - row.outMinor) / 100),
                                  borderColor: themeColor('--info'),
                                  backgroundColor: themeColor('--info', 0.12),
                                  // Filled only in this mode: one line over a baseline reads as an
                                  // area, two filled ones read as a stack, which these are not.
                                  fill: true,
                                  ...line,
                              },
                          ]
                        : [
                              // Blue against grey rather than the green and red the amounts use in a
                              // table. There a colour marks one figure's sign; here the two lines are
                              // being compared, and a red line reads as the alarming one rather than
                              // as the other one — which is wrong for a month where spending is
                              // simply lower than income.
                              {
                                  label: t((d) => d.pages.walletReports.moneyIn),
                                  data: months.map((row) => row.inMinor / 100),
                                  borderColor: themeColor('--info'),
                                  backgroundColor: themeColor('--info', 0.08),
                                  fill: false,
                                  ...line,
                              },
                              {
                                  label: t((d) => d.pages.walletReports.moneyOut),
                                  data: months.map((row) => row.outMinor / 100),
                                  borderColor: themeColor('--muted-foreground'),
                                  backgroundColor: themeColor('--muted-foreground', 0.08),
                                  fill: false,
                                  ...line,
                              },
                          ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // One tooltip for the whole month rather than one per line, so the two figures are
                // read against each other — which is the only reason they share an axis.
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            color: ink,
                            font: { size: 10, weight: 600 },
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: (item) =>
                                `${item.dataset.label ?? ''}: ${formatMinor(Math.round((item.parsed.y ?? 0) * 100), currency, locale)}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: ink, font: { size: 10 } } },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: grid },
                        ticks: {
                            color: ink,
                            font: { size: 10 },
                            // Thousands as `12k`: a year of axis labels at full precision is a
                            // column of digits nobody reads.
                            callback: (value) =>
                                typeof value === 'number' && Math.abs(value) >= 1000
                                    ? `${String(Math.round(value / 100) / 10)}k`
                                    : String(value),
                        },
                    },
                },
            },
        };
    }, [months, flowMode, currency, locale, t]);

    /** A ring, not a pie: the hole carries the total, which is the figure the slices are shares of. */
    const donut = (values: number[], colors: string[]): ChartConfiguration<'doughnut'> => ({
        type: 'doughnut',
        data: { labels: [], datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
        },
    });

    const breakdownConfig = useMemo<ChartConfiguration>(
        () =>
            donut(
                breakdown.slice(0, 6).map((row) => row.totalMinor / 100),
                [
                    themeColor('--info', 0.9),
                    themeColor('--info', 0.7),
                    themeColor('--info', 0.5),
                    themeColor('--info', 0.35),
                    themeColor('--info', 0.22),
                    themeColor('--muted-foreground', 0.25),
                ],
            ),
        [breakdown],
    );

    const qualityConfig = useMemo<ChartConfiguration>(
        () =>
            donut(
                [clean, reports?.attention.unclassifiedCount ?? 0],
                [themeColor('--success', 0.8), themeColor('--border')],
            ),
        [clean, reports],
    );

    const currencyConfig = useMemo<ChartConfiguration>(
        () =>
            donut(
                (reports?.totals ?? []).map((row) => row.count),
                [
                    themeColor('--info', 0.85),
                    themeColor('--info', 0.5),
                    themeColor('--info', 0.25),
                    themeColor('--border'),
                ],
            ),
        [reports],
    );

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.walletReports.title)}>
                    <Panel title={t((d) => d.pages.walletReports.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    return (
        <AppShell>
            <Head title={t((d) => d.pages.walletReports.title)} />
            <Page title={t((d) => d.pages.walletReports.title)} lede={t((d) => d.pages.walletReports.description)}>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-wrap items-center gap-2">
                        {/* The years this taxpayer has records in, newest first, rather than the last
                            four — which was a menu of empty reports for one person and a menu missing
                            a decade for another. */}
                        <PanelSelect
                            className="select-compact"
                            value={period}
                            onValueChange={setPeriod}
                            // One calendar on the control, not one per year: the mark says what this
                            // chooses, and repeating it down the menu distinguished nothing.
                            icon={<CalendarRange className="size-3.5" aria-hidden="true" />}
                            options={[
                                ...(reports?.years ?? []).map((year) => ({
                                    value: String(year),
                                    label: String(year),
                                })),
                                { value: '12m', label: t((d) => d.pages.walletReports.lastTwelve) },
                            ]}
                        />
                        {currencies.length < 2 ? null : (
                            <Tabs
                                value={currency}
                                onChange={setSelected}
                                options={currencies.map((code) => ({ value: code, label: code }))}
                            />
                        )}
                        <span className="note">
                            {reports === null
                                ? ''
                                : `${formatDate(reports.from, locale)} – ${formatDate(reports.to, locale)}`}
                        </span>
                    </div>
                    <PanelButton
                        inline
                        onClick={() => {
                            toast.info(t((d) => d.pages.walletReports.exportSoon));
                        }}
                    >
                        <Download className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.walletReports.exportReport)}
                    </PanelButton>
                </div>

                {failed ? <p className="note">{t((d) => d.pages.walletReports.loadFailed)}</p> : null}

                {reports === null && !failed ? (
                    <div className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {[0, 1, 2, 3].map((index) => (
                                <Skeleton key={index} className="tile h-24 w-full" />
                            ))}
                        </div>
                        <Skeleton className="tile h-80 w-full" />
                    </div>
                ) : null}

                {reports === null ? null : (
                    <>
                        {/* What the period came to, before any breakdown of it. */}
                        {/* The four figures the panels below are breakdowns of, in the tile the
                            account, users and logs screens already use. */}
                        <StatGrid>
                            <Stat
                                label={t((d) => d.pages.walletReports.moneyIn)}
                                value={money(moneyIn)}
                                tone="success"
                                icon={<ArrowDownLeft className="size-3.5" aria-hidden="true" />}
                                sub={
                                    <Change
                                        percent={change(moneyIn, previous?.inMinor ?? 0)}
                                        note={t((d) => d.pages.walletReports.versusPrevious)}
                                    />
                                }
                            />
                            <Stat
                                label={t((d) => d.pages.walletReports.moneyOut)}
                                value={money(moneyOut)}
                                tone="destructive"
                                icon={<ArrowUpRight className="size-3.5" aria-hidden="true" />}
                                sub={
                                    <Change
                                        percent={change(moneyOut, previous?.outMinor ?? 0)}
                                        note={t((d) => d.pages.walletReports.versusPrevious)}
                                        /* Spending more than before is not good news, so the colour
                                           of the arrow is inverted here. */
                                        invert
                                    />
                                }
                            />
                            <Stat
                                label={t((d) => d.pages.walletReports.net)}
                                value={money(moneyIn - moneyOut)}
                                tone="accent"
                                icon={<Activity className="size-3.5" aria-hidden="true" />}
                                sub={
                                    moneyIn === 0
                                        ? undefined
                                        : t((d) => d.pages.walletReports.retained).replace(
                                              '{percent}',
                                              String(Math.round(((moneyIn - moneyOut) / moneyIn) * 100)),
                                          )
                                }
                            />
                            <Stat
                                label={t((d) => d.pages.walletReports.transactions)}
                                value={String(totals?.count ?? 0)}
                                icon={<ArrowLeftRight className="size-3.5" aria-hidden="true" />}
                                sub={
                                    reports.attention.unclassifiedCount === 0
                                        ? undefined
                                        : t((d) => d.pages.walletReports.needAttention).replace(
                                              '{count}',
                                              String(reports.attention.unclassifiedCount),
                                          )
                                }
                            />
                        </StatGrid>

                        <section className="report-split grid gap-4">
                            <Panel
                                title={t((d) => d.pages.walletReports.cashFlow)}
                                description={t((d) => d.pages.walletReports.cashFlowHint)}
                                icon={<TrendingUp className="size-4" aria-hidden="true" />}
                                aside={
                                    <Tabs
                                        value={flowMode}
                                        onChange={setFlowMode}
                                        options={[
                                            {
                                                value: 'all',
                                                label: t((d) => d.pages.walletReports.flowBoth),
                                                icon: <ArrowLeftRight className="size-3" aria-hidden="true" />,
                                            },
                                            {
                                                value: 'net',
                                                label: t((d) => d.pages.walletReports.flowNet),
                                                icon: <Activity className="size-3" aria-hidden="true" />,
                                            },
                                        ]}
                                    />
                                }
                            >
                                {months.length === 0 ? (
                                    <p className="note">{t((d) => d.pages.walletReports.empty)}</p>
                                ) : (
                                    <div className="chart-tall">
                                        <Chart config={flowConfig} label={t((d) => d.pages.walletReports.cashFlow)} />
                                    </div>
                                )}
                            </Panel>

                            <Panel
                                title={t((d) => d.pages.walletReports.composition)}
                                description={t((d) => d.pages.walletReports.compositionHint)}
                                icon={<ChartPie className="size-4" aria-hidden="true" />}
                            >
                                <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                                    <DonutTile
                                        config={breakdownConfig}
                                        centre={breakdown.length === 0 ? '—' : '100%'}
                                        centreNote={t((d) => d.pages.walletReports.spend)}
                                        label={t((d) => d.pages.walletReports.topCategory)}
                                        value={breakdown[0]?.label ?? '—'}
                                        note={
                                            breakdown[0] === undefined || breakdownTotal === 0
                                                ? ''
                                                : `${money(breakdown[0].totalMinor)} · ${String(Math.round((breakdown[0].totalMinor / breakdownTotal) * 100))}%`
                                        }
                                    />
                                    <DonutTile
                                        config={currencyConfig}
                                        centre={String(currencies.length)}
                                        centreNote={t((d) => d.pages.walletReports.currencies)}
                                        label={t((d) => d.pages.walletReports.mainCurrency)}
                                        value={currency}
                                        note={
                                            reports.attention.totalCount === 0
                                                ? ''
                                                : `${String(Math.round(((totals?.count ?? 0) / reports.attention.totalCount) * 100))}% ${t((d) => d.pages.walletReports.ofActivity)}`
                                        }
                                    />
                                    <DonutTile
                                        config={qualityConfig}
                                        centre={`${String(completeness)}%`}
                                        centreNote={t((d) => d.pages.walletReports.clean)}
                                        label={t((d) => d.pages.walletReports.dataQuality)}
                                        value={t((d) => d.pages.walletReports.cleanCount).replace(
                                            '{count}',
                                            String(clean),
                                        )}
                                        note={t((d) => d.pages.walletReports.reviewCount).replace(
                                            '{count}',
                                            String(reports.attention.unclassifiedCount),
                                        )}
                                    />
                                </div>
                            </Panel>
                        </section>

                        {/* Two readings of the same year, in one card: how each month went, and
                            which individual movements were large enough to explain it. They were two
                            panels saying "here is the year" twice, one under the other. */}
                        <Panel
                            title={t((d) =>
                                tableView === 'months'
                                    ? d.pages.walletReports.yearByMonth
                                    : d.pages.walletReports.largest,
                            )}
                            description={t((d) =>
                                tableView === 'months'
                                    ? d.pages.walletReports.yearByMonthHint
                                    : d.pages.walletReports.largestHint,
                            )}
                            icon={
                                tableView === 'months' ? (
                                    <CalendarRange className="size-4" aria-hidden="true" />
                                ) : (
                                    <Trophy className="size-4" aria-hidden="true" />
                                )
                            }
                            flush
                            aside={
                                <div className="flex flex-wrap items-center gap-2">
                                    <Tabs
                                        value={tableView}
                                        onChange={setTableView}
                                        options={[
                                            {
                                                value: 'months',
                                                label: t((d) => d.pages.walletReports.tabMonths),
                                                icon: <CalendarRange className="size-3" aria-hidden="true" />,
                                            },
                                            {
                                                value: 'largest',
                                                label: t((d) => d.pages.walletReports.tabLargest),
                                                icon: <Trophy className="size-3" aria-hidden="true" />,
                                            },
                                        ]}
                                    />
                                </div>
                            }
                        >
                            {tableView === 'months' ? (
                                months.length === 0 ? (
                                    <p className="note p-4">{t((d) => d.pages.walletReports.empty)}</p>
                                ) : (
                                    <DataTable
                                        head={
                                            <>
                                                <th>{t((d) => d.pages.walletReports.month)}</th>
                                                <th className="col-figure">
                                                    {t((d) => d.pages.walletReports.moneyIn)}
                                                </th>
                                                <th className="col-figure">
                                                    {t((d) => d.pages.walletReports.moneyOut)}
                                                </th>
                                                <th className="col-figure">{t((d) => d.pages.walletReports.net)}</th>
                                                <th className="col-figure">{t((d) => d.pages.walletReports.colTxs)}</th>
                                                <th>{t((d) => d.pages.walletReports.colTop)}</th>
                                                <th>{t((d) => d.pages.walletReports.monthlySpend)}</th>
                                            </>
                                        }
                                    >
                                        {months.map((row) => {
                                            const net = row.inMinor - row.outMinor;

                                            return (
                                                <tr key={row.month}>
                                                    <td className="col-primary">{monthLabel(row.month)}</td>
                                                    <td className="col-figure is-in">{money(row.inMinor)}</td>
                                                    <td className="col-figure is-out">{money(-row.outMinor)}</td>
                                                    <td
                                                        className={`col-figure font-semibold${net < 0 ? ' is-out' : ''}`}
                                                    >
                                                        {money(net)}
                                                    </td>
                                                    <td className="col-figure">{row.count}</td>
                                                    <td>
                                                        {row.topCategory === null ? (
                                                            <span className="note">—</span>
                                                        ) : (
                                                            <Pill tone="accent">
                                                                <Shapes aria-hidden="true" />
                                                                {categoryLabel(row.topCategory)}
                                                            </Pill>
                                                        )}
                                                    </td>
                                                    <td className="col-hide">
                                                        <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
                                                            <div
                                                                className="bg-destructive/60 h-full rounded-full"
                                                                style={{
                                                                    width: `${String((row.outMinor / widest(months.map((entry) => entry.outMinor))) * 100)}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </DataTable>
                                )
                            ) : largest.length === 0 ? (
                                <p className="note p-4">{t((d) => d.pages.walletReports.empty)}</p>
                            ) : (
                                <DataTable
                                    head={
                                        <>
                                            <th>{t((d) => d.pages.walletReports.date)}</th>
                                            <th>{t((d) => d.pages.walletReports.tabMerchant)}</th>
                                            <th className="col-figure">{t((d) => d.pages.walletReports.amount)}</th>
                                        </>
                                    }
                                >
                                    {largest.map((row) => (
                                        <tr key={row.id}>
                                            {/* The direction, said once more at the start of the
                                                row. The sign and the colour already carry it at the
                                                far end, and in a table mixing both ways the eye
                                                reaches the date first. */}
                                            <td className="whitespace-nowrap tabular-nums">
                                                <span className="flex items-center gap-2">
                                                    <span
                                                        className={`direction-mark ${
                                                            row.amountMinor < 0
                                                                ? 'bg-destructive/10 text-destructive'
                                                                : 'bg-success/10 text-success'
                                                        }`}
                                                    >
                                                        {row.amountMinor < 0 ? (
                                                            <ArrowUpRight className="size-3" aria-hidden="true" />
                                                        ) : (
                                                            <ArrowDownLeft className="size-3" aria-hidden="true" />
                                                        )}
                                                    </span>
                                                    {formatDate(row.bookedAt, locale)}
                                                </span>
                                            </td>
                                            <td className="col-primary">
                                                {row.counterparty ??
                                                    row.description ??
                                                    t((d) => d.pages.walletReports.noCounterparty)}
                                            </td>
                                            <td
                                                className={`col-figure font-semibold ${
                                                    row.amountMinor < 0 ? 'is-out' : 'is-in'
                                                }`}
                                            >
                                                {formatMinor(row.amountMinor, row.currencyCode, locale)}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            )}
                        </Panel>

                        <Panel
                            title={t((d) => d.pages.walletReports.breakdown)}
                            description={t((d) => d.pages.walletReports.breakdownHint)}
                            icon={<ChartColumn className="size-4" aria-hidden="true" />}
                            aside={
                                <Tabs
                                    value={breakdownView}
                                    onChange={setBreakdownView}
                                    options={[
                                        {
                                            value: 'category',
                                            label: t((d) => d.pages.walletReports.tabCategory),
                                            icon: <Shapes className="size-3" aria-hidden="true" />,
                                        },
                                        {
                                            value: 'tag',
                                            label: t((d) => d.pages.walletReports.tabTag),
                                            icon: <TagIcon className="size-3" aria-hidden="true" />,
                                        },
                                        {
                                            value: 'merchant',
                                            label: t((d) => d.pages.walletReports.tabMerchant),
                                            icon: <Users className="size-3" aria-hidden="true" />,
                                        },
                                    ]}
                                />
                            }
                        >
                            {breakdown.length === 0 ? (
                                <p className="note">{t((d) => d.pages.walletReports.empty)}</p>
                            ) : (
                                <div className="grid gap-4 lg:grid-cols-[.75fr_1.25fr]">
                                    <div className="flex items-center justify-center">
                                        <div className="chart-donut-lead">
                                            <Chart
                                                config={breakdownConfig}
                                                label={t((d) => d.pages.walletReports.breakdown)}
                                            />
                                            <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                                                <div>
                                                    <p className="note">
                                                        {t((d) => d.pages.walletReports.totalExpenses)}
                                                    </p>
                                                    <p className="mt-1 text-lg font-semibold tracking-tight">
                                                        {money(breakdownTotal)}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <ul className="divide-border divide-y">
                                        {breakdown.map((row) => (
                                            <li key={row.key} className="py-2.5 first:pt-0">
                                                <div className="flex items-baseline justify-between gap-3">
                                                    <span className="truncate text-xs font-semibold">{row.label}</span>
                                                    <span className="flex shrink-0 items-baseline gap-2">
                                                        <span className="note">
                                                            {breakdownTotal === 0
                                                                ? ''
                                                                : `${String(Math.round((row.totalMinor / breakdownTotal) * 100))}%`}
                                                        </span>
                                                        <span className="text-sm font-semibold tabular-nums">
                                                            {money(row.totalMinor)}
                                                        </span>
                                                    </span>
                                                </div>
                                                <div className="bg-muted mt-1.5 h-1.5 overflow-hidden rounded-full">
                                                    <div
                                                        className="bg-info/70 h-full rounded-full"
                                                        style={{
                                                            width: `${String((row.totalMinor / widest(breakdown.map((entry) => entry.totalMinor))) * 100)}%`,
                                                        }}
                                                    />
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </Panel>

                        <section className="report-split-wide grid gap-4">
                            <Panel
                                title={t((d) => d.pages.walletReports.movement)}
                                description={t((d) => d.pages.walletReports.movementHint)}
                                icon={<ArrowLeftRight className="size-4" aria-hidden="true" />}
                                flush
                                aside={
                                    <Tabs
                                        value={movementView}
                                        onChange={setMovementView}
                                        options={[
                                            {
                                                value: 'bank',
                                                label: t((d) => d.pages.walletReports.tabBank),
                                                icon: <Landmark className="size-3" aria-hidden="true" />,
                                            },
                                            {
                                                value: 'currency',
                                                label: t((d) => d.pages.walletReports.tabCurrency),
                                                icon: <Coins className="size-3" aria-hidden="true" />,
                                            },
                                            {
                                                value: 'account',
                                                label: t((d) => d.pages.walletReports.tabAccount),
                                                icon: <WalletCards className="size-3" aria-hidden="true" />,
                                            },
                                        ]}
                                    />
                                }
                            >
                                {movement.length === 0 ? (
                                    <p className="note p-4">{t((d) => d.pages.walletReports.empty)}</p>
                                ) : (
                                    <>
                                        {/* The three figures the table below is a breakdown of, so the
                                            column totals never have to be added up by eye. */}
                                        <div className="divide-border border-border grid grid-cols-3 divide-x border-b">
                                            <div className="p-3 text-center">
                                                <p className="note">{t((d) => d.pages.walletReports.moneyIn)}</p>
                                                <p className="text-success mt-1 text-sm font-semibold tabular-nums">
                                                    {money(moneyIn)}
                                                </p>
                                            </div>
                                            <div className="p-3 text-center">
                                                <p className="note">{t((d) => d.pages.walletReports.moneyOut)}</p>
                                                <p className="text-destructive mt-1 text-sm font-semibold tabular-nums">
                                                    {money(-moneyOut)}
                                                </p>
                                            </div>
                                            <div className="p-3 text-center">
                                                <p className="note">{t((d) => d.pages.walletReports.net)}</p>
                                                <p className="mt-1 text-sm font-semibold tabular-nums">
                                                    {money(moneyIn - moneyOut)}
                                                </p>
                                            </div>
                                        </div>
                                        <DataTable
                                            head={
                                                <>
                                                    <th>{t((d) => d.pages.walletReports.source)}</th>
                                                    <th>{t((d) => d.pages.walletReports.currency)}</th>
                                                    <th className="col-figure">
                                                        {t((d) => d.pages.walletReports.moneyIn)}
                                                    </th>
                                                    <th className="col-figure">
                                                        {t((d) => d.pages.walletReports.moneyOut)}
                                                    </th>
                                                </>
                                            }
                                        >
                                            {movement.map((row) => (
                                                <tr key={row.key}>
                                                    <td className="col-primary">{row.label}</td>
                                                    <td className="note">{row.currencyCode}</td>
                                                    <td className="col-figure is-in">
                                                        {formatMinor(row.inMinor, row.currencyCode, locale)}
                                                    </td>
                                                    <td className="col-figure is-out">
                                                        {formatMinor(-row.outMinor, row.currencyCode, locale)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </DataTable>
                                    </>
                                )}
                            </Panel>

                            <Panel
                                title={t((d) => d.pages.walletReports.attention)}
                                description={t((d) => d.pages.walletReports.attentionHint)}
                                icon={<TriangleAlert className="size-4" aria-hidden="true" />}
                                flush
                            >
                                <div className="divide-border divide-y">
                                    {reports.attention.unclassifiedCount === 0 ? null : (
                                        <AttentionRow
                                            tone="warning"
                                            icon={<CircleHelp className="size-4" aria-hidden="true" />}
                                            title={t((d) => d.pages.walletReports.needsTreatment).replace(
                                                '{count}',
                                                String(reports.attention.unclassifiedCount),
                                            )}
                                            note={reports.attention.unclassifiedMinor
                                                .map((total) =>
                                                    formatMinor(total.totalMinor, total.currencyCode, locale),
                                                )
                                                .join(' · ')}
                                            onClick={() => {
                                                router.visit('/wallet/transactions');
                                            }}
                                        />
                                    )}
                                    {reports.attention.duplicateGroups === 0 ? null : (
                                        <AttentionRow
                                            tone="destructive"
                                            icon={<CopyCheck className="size-4" aria-hidden="true" />}
                                            title={t((d) => d.pages.walletReports.possibleDuplicates).replace(
                                                '{count}',
                                                String(reports.attention.duplicateGroups),
                                            )}
                                            note={t((d) => d.pages.walletReports.duplicatesNote)}
                                            onClick={() => {
                                                router.visit('/wallet/transactions');
                                            }}
                                        />
                                    )}
                                    {reports.attention.unclassifiedCount === 0 &&
                                    reports.attention.duplicateGroups === 0 ? (
                                        <p className="note p-4">{t((d) => d.pages.walletReports.allClear)}</p>
                                    ) : null}
                                </div>
                                <div className="border-border border-t p-4">
                                    <div className="mb-2 flex items-center justify-between">
                                        <span className="note font-semibold">
                                            {t((d) => d.pages.walletReports.completeness)}
                                        </span>
                                        <span className="text-xs font-semibold">{completeness}%</span>
                                    </div>
                                    <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                                        <div
                                            className="bg-info h-full rounded-full"
                                            style={{ width: `${String(completeness)}%` }}
                                        />
                                    </div>
                                </div>
                            </Panel>
                        </section>
                    </>
                )}
            </Page>
        </AppShell>
    );
}

/**
 * How a figure moved against the period before it.
 *
 * The sign carries the colour, and `invert` flips which sign is the good one — spending more than
 * last year is a rise and bad news, income rising is the same rise and good news, and a tile that
 * coloured both green would be reporting the arithmetic rather than the meaning.
 */
function Change(props: { percent: number | null; note: string; invert?: boolean }): JSX.Element {
    if (props.percent === null) {
        return <>{props.note}</>;
    }

    const rose = props.percent > 0;
    const good = props.invert === true ? !rose : rose;

    return (
        <>
            <span className={good ? 'text-success font-semibold' : 'text-destructive font-semibold'}>
                {rose ? '+' : ''}
                {props.percent.toFixed(1)}%
            </span>{' '}
            {props.note}
        </>
    );
}

/** A ring with its headline in the hole, and the reading spelled out beside it. */
function DonutTile(props: {
    config: ChartConfiguration;
    centre: string;
    centreNote: string;
    label: string;
    value: string;
    note: string;
}): JSX.Element {
    return (
        <div className="border-border flex items-center gap-4 rounded-xl border p-3">
            <div className="chart-donut">
                <Chart config={props.config} label={props.label} />
                <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                    <div>
                        <p className="text-base font-semibold">{props.centre}</p>
                        <p className="note donut-note">{props.centreNote}</p>
                    </div>
                </div>
            </div>
            <div className="min-w-0">
                <p className="metric-label">{props.label}</p>
                <p className="mt-1 truncate text-sm font-semibold">{props.value}</p>
                <p className="note mt-1">{props.note}</p>
            </div>
        </div>
    );
}

/** One thing to go and fix, as a row that goes there. */
function AttentionRow(props: {
    tone: 'warning' | 'destructive';
    icon: JSX.Element;
    title: string;
    note: string;
    onClick: () => void;
}): JSX.Element {
    return (
        <button
            type="button"
            className="hover:bg-secondary flex w-full items-center gap-3 px-4 py-3 text-left"
            onClick={props.onClick}
        >
            <span
                className={`grid size-8 shrink-0 place-items-center rounded-xl ${
                    props.tone === 'warning'
                        ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                        : 'bg-destructive/10 text-destructive'
                }`}
            >
                {props.icon}
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-xs font-semibold">{props.title}</span>
                <span className="note block">{props.note}</span>
            </span>
            <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
        </button>
    );
}
