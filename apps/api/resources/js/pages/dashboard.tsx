import { Head, router } from '@inertiajs/react';
import {
    Banknote,
    Calculator,
    CalendarClock,
    ChartLine,
    CircleHelp,
    HandCoins,
    Landmark,
    PiggyBank,
    Plus,
    RefreshCw,
    TrendingUp,
    Upload,
    Wallet,
    Zap,
} from 'lucide-react';
import { useEffect, useMemo, useState, type JSX } from 'react';
import type { ChartConfiguration, Plugin } from 'chart.js';

import { getWalletOverview } from '../api/walletApi';
import { apiRequest } from '../lib/http';
import AppShell from '../components/AppShell';
import { Chart } from '../components/Chart';
import {
    EmptyState,
    IconBadge,
    Kpi,
    Page,
    Panel,
    PanelButton,
    PanelNote,
    PanelSelect,
    Pill,
} from '../components/panel';
import { Skeleton } from '../components/ui/skeleton';
import { useRememberedState } from '../hooks/useRememberedState';
import { useTranslation } from '../i18n';
import { formatDate } from '../lib/format';
import { getActiveSubjectId } from '../lib/subjectStore';
import { useCurrencies } from '../lib/sharedProps';
import { themeColor } from '../lib/themeColor';
import { cn } from '../lib/utils';
import {
    addHoldings,
    BASE_CURRENCY,
    formatMinor,
    type Holding,
    type NetWorth,
    type WalletOverview as Overview,
} from '../types/wallet';

/** Mirrors `routes/web.php`'s named route, so the path appears once on this screen. */
const ACCOUNTS_PATH = '/wallet/accounts';
const DOCUMENTS_PATH = '/documents';
const TRANSACTIONS_PATH = '/wallet/transactions';
const TAXES_PATH = '/taxes';
const SPV_PATH = '/spv';

/** Three months to five years, as whole months, matching what the endpoint accepts. */
const FX_WINDOWS = ['3', '6', '12', '60'];

type Translate = ReturnType<typeof useTranslation>['t'];

/**
 * Where the money is, in the order the bar draws it. Class names are written out whole so the
 * stylesheet build finds them; the colours are the palette's own, so a changed palette recolours
 * the bar with it.
 */
const GROUPS = [
    { key: 'deposits', swatch: 'bg-[var(--brand-600)]' },
    { key: 'general', swatch: 'bg-[var(--brand-300)]' },
    { key: 'cash', swatch: 'bg-success' },
    { key: 'other', swatch: 'bg-amber-500' },
] as const;

/**
 * The dashboard: what the taxpayer has, where it is, and what their deposits earn.
 *
 * The first screen after signing in, so it answers the first question a person has — how much, and
 * where. Wallet itself has no page of its own any more: in the menu it only opens accounts,
 * transactions and documents, and this is where its summary lives.
 *
 * ## One figure first
 *
 * "What you have" is everything added together in the display currency, and the bar under it says
 * where that money is — deposits, General, cash, other accounts — as shares of the same figure, with
 * the original currencies beside each. Nothing is shown twice: a deposit funded from General moved
 * money, so it sits under deposits and not also under General.
 *
 * The conversion is an indication for a dashboard, labelled with the BNR rate date it used. Every
 * figure a tax return reads is converted by the tax engine, with its own rate and provenance.
 */
export default function Dashboard(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [overview, setOverview] = useState<Overview | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        getWalletOverview(subjectId).then(
            (loaded) => {
                if (active) {
                    setOverview(loaded);
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
    }, [subjectId]);

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.dashboard.title)}>
                    <Panel title={t((d) => d.dashboard.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    const totals = overview?.totals ?? null;
    const loading = overview === null && !failed;

    /** The original currencies of a figure, one after another — never added together. */
    const perCurrency = (amounts: Record<string, number>): string =>
        Object.entries(amounts)
            .map(([currency, minor]) => formatMinor(minor, currency, locale))
            .join(' · ');

    const converted = (total: NetWorth): string => formatMinor(total.amountMinor, total.currencyCode, locale);

    const percent = (share: number): string =>
        new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 }).format(share);

    /** A holding's converted figure, or zero in the display currency when it holds nothing. */
    const holdingValue = (holding: Holding | undefined): string =>
        holding === undefined
            ? '—'
            : Object.keys(holding.amounts).length === 0
              ? formatMinor(0, holding.converted.currencyCode, locale)
              : converted(holding.converted);

    // The shares are of the converted figures, so a currency with no rate is left out of both the
    // part and the whole rather than drawn at a size nobody computed.
    /*
     * General and what sits in accounts held elsewhere, as one figure.
     *
     * The payload keeps them apart because they answer different questions — how much, and where —
     * but a transfer to the taxpayer's own account at another bank is not a different kind of
     * holding, and a row of its own would invite the question "is that mine?" about money that is.
     */
    const held =
        totals === null
            ? null
            : { ...totals.holdings, general: addHoldings(totals.holdings.general, totals.holdings.internal) };

    const groups =
        held === null
            ? []
            : GROUPS.map((group) => ({ ...group, holding: held[group.key] })).filter(
                  // Only once there is one: most taxpayers have no broker or exchange account, and a
                  // permanent zero would be a legend entry answering a question nobody asked.
                  (group) => group.key !== 'other' || Object.keys(group.holding.amounts).length > 0,
              );
    const whole = groups.reduce((sum, group) => sum + Math.max(0, group.holding.converted.amountMinor), 0);
    const shareOf = (holding: Holding): number => (whole > 0 ? Math.max(0, holding.converted.amountMinor) / whole : 0);

    const owedToMe = totals?.owedToMe;
    const depositsWhole = totals?.holdings.deposits.converted.amountMinor ?? 0;

    return (
        <AppShell>
            <Head title={t((d) => d.dashboard.title)} />
            <Page title={t((d) => d.dashboard.title)} lede={t((d) => d.dashboard.lede)}>
                {failed ? <p className="note">{t((d) => d.pages.wallet.loadFailed)}</p> : null}

                {/*
                    What there is, and where it sits — side by side rather than stacked.
                    The figure and its provenance occupy the left; the allocation reads as a list on the right,
                    one row per holding with the amount in its own column. Stacked under a full-width bar, the
                    four amounts were four columns a reader had to scan across to compare; down a column they
                    compare themselves.
                */}
                <section className="border-border bg-card shadow-float overflow-hidden rounded-xl border">
                    <div className="flex flex-col gap-5 p-5 xl:flex-row xl:gap-8">
                        <div className="border-border overview-figure min-w-0 xl:border-r xl:pr-8">
                            <div className="flex items-center gap-2.5">
                                <IconBadge tone="brand">
                                    <Wallet className="size-4" />
                                </IconBadge>
                                <div className="min-w-0">
                                    <div className="panel-title">{t((d) => d.pages.walletOverview.netWorth)}</div>
                                    <div className="note">{t((d) => d.pages.walletOverview.netWorthScope)}</div>
                                </div>
                            </div>

                            <div className="overview-total mt-5">
                                {totals === null ? (
                                    loading ? (
                                        <Skeleton className="h-9 w-64" />
                                    ) : (
                                        '—'
                                    )
                                ) : (
                                    converted(totals.netWorth)
                                )}
                            </div>

                            {/* Where the figure came from, as a mark rather than a sentence: a partial total
                                is a different kind of number from a complete one, and that reads faster as a
                                state than as prose a reader has to finish. */}
                            {totals === null ? null : (
                                <div className="mt-2.5 flex flex-wrap items-center gap-2">
                                    <Pill tone={totals.netWorth.complete ? 'accent' : 'locked'}>
                                        {t((d) =>
                                            totals.netWorth.complete
                                                ? d.pages.walletOverview.netWorthRecorded
                                                : d.pages.walletOverview.netWorthIncomplete,
                                        )}
                                    </Pill>
                                    <span className="note">{netWorthNote(totals.netWorth, t)}</span>
                                </div>
                            )}
                        </div>

                        <div className="min-w-0 flex-1">
                            <div className="flex items-center justify-between gap-2">
                                <div className="panel-title">{t((d) => d.pages.walletOverview.whereItSits)}</div>
                                <span className="note">{t((d) => d.pages.walletOverview.allocationOf)}</span>
                            </div>

                            <div
                                className="bg-muted mt-3.5 flex h-1.5 gap-0.5 overflow-hidden rounded-full"
                                aria-hidden="true"
                            >
                                {groups.map((group) =>
                                    shareOf(group.holding) === 0 ? null : (
                                        <div
                                            key={group.key}
                                            className={cn('h-full', group.swatch)}
                                            style={{ width: `${String(shareOf(group.holding) * 100)}%` }}
                                        />
                                    ),
                                )}
                            </div>

                            <dl className="divide-border mt-3 divide-y">
                                {groups.map((group) => {
                                    /* What the share is not: the deposit count and the per-currency split
                                       are the holding read out, and they belong under the name rather than
                                       in the line that identifies it. */
                                    const detail = [
                                        group.key === 'deposits' && totals !== null
                                            ? t((d) => d.pages.walletOverview.depositCount).replace(
                                                  '{count}',
                                                  String(totals.depositCount),
                                              )
                                            : '',
                                        perCurrency(group.holding.amounts),
                                    ]
                                        .filter((part) => part !== '')
                                        .join(' · ');

                                    return (
                                        <div
                                            key={group.key}
                                            className="flex items-baseline justify-between gap-x-4 py-2.5 first:pt-0 last:pb-0"
                                        >
                                            {/* Two lines at every width. The first says which holding and how
                                                much of the total it is, with the amount against the right edge;
                                                the reading sits under it. All of it inline made a line too long
                                                to fit beside the amount even on a desktop, so the amount wrapped
                                                onto a line of its own at the left — the one place a figure should
                                                never be. */}
                                            <dt className="flex min-w-0 flex-col gap-y-1 text-xs font-medium">
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <span
                                                        className={cn('size-2 shrink-0 rounded-full', group.swatch)}
                                                    />
                                                    <span className="truncate">
                                                        {t((d) => d.pages.walletOverview[group.key])}
                                                    </span>
                                                    {/* The share as a mark, not as the first of three
                                                        full stops' worth of text: it is the one number here
                                                        that is about the whole rather than this holding. */}
                                                    <Pill tone="neutral" className="shrink-0 tabular-nums">
                                                        {percent(shareOf(group.holding))}
                                                    </Pill>
                                                </span>
                                                {detail === '' ? null : (
                                                    /* Indented by the dot and its gap, so the reading
                                                       starts where the name above it does. */
                                                    <span className="note pl-4">{detail}</span>
                                                )}
                                            </dt>
                                            <dd className="shrink-0 text-sm font-semibold tabular-nums">
                                                {holdingValue(group.holding)}
                                            </dd>
                                        </div>
                                    );
                                })}
                            </dl>
                        </div>
                    </div>

                    {/* The same footnote the permission matrix wears: it qualifies every figure above it,
                        not the one it happens to sit under. */}
                    <PanelNote>{t((d) => d.pages.walletOverview.generalNote)}</PanelNote>
                </section>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(13rem,1fr))] gap-3">
                    {/* What can be spent today, as one figure: General and cash are both money to hand.
                        Each part stays named underneath. */}
                    <Kpi
                        tone="success"
                        icon={<Banknote className="size-4" />}
                        label={t((d) => d.pages.walletOverview.available)}
                        value={holdingValue(totals?.holdings.available)}
                        sub={
                            totals === null
                                ? undefined
                                : [
                                      {
                                          label: t((d) => d.pages.walletOverview.general),
                                          holding: held?.general ?? totals.holdings.general,
                                      },
                                      { label: t((d) => d.pages.walletOverview.cash), holding: totals.holdings.cash },
                                  ]
                                      .filter((part) => Object.keys(part.holding.amounts).length > 0)
                                      .map((part) => `${part.label} ${perCurrency(part.holding.amounts)}`)
                                      .join(' · ')
                        }
                    />
                    {/* Only when the rule actually fired. A balance is the sum of what was recorded, and
                        an account cannot go below zero, so money leaving before anything arrived is
                        taken as money the taxpayer already had. That assumption is inside the figures
                        above and indistinguishable from money the records account for — which is how a
                        total ends up larger than a person expects with nothing to explain it. */}
                    {totals === null || Object.keys(totals.assumedOpening.amounts).length === 0 ? null : (
                        <Kpi
                            tone="info"
                            icon={<CircleHelp className="size-4" />}
                            label={t((d) => d.pages.walletOverview.assumedOpening)}
                            value={holdingValue(totals.assumedOpening)}
                            sub={[
                                t((d) => d.pages.walletOverview.assumedOpeningHint),
                                perCurrency(totals.assumedOpening.amounts),
                            ].join(' · ')}
                        />
                    )}

                    {/* Only once something is lent: a permanent zero would read as a debt being tracked. */}
                    {owedToMe === undefined || Object.keys(owedToMe.amounts).length === 0 ? null : (
                        <Kpi
                            tone="amber"
                            icon={<HandCoins className="size-4" />}
                            label={t((d) => d.pages.walletOverview.owedToMe)}
                            value={holdingValue(owedToMe)}
                            sub={[t((d) => d.pages.walletOverview.owedToMeHint), perCurrency(owedToMe.amounts)].join(
                                ' · ',
                            )}
                        />
                    )}
                    <Kpi
                        tone="brand"
                        icon={<TrendingUp className="size-4" />}
                        label={t((d) => d.pages.walletOverview.interestAtMaturity)}
                        value={holdingValue(totals?.interestAtMaturity)}
                        sub={subLine(
                            t((d) => d.pages.walletOverview.interestAtMaturityHint),
                            totals?.interestAtMaturity,
                            perCurrency,
                        )}
                    />
                    <Kpi
                        tone="success"
                        icon={<PiggyBank className="size-4" />}
                        label={t((d) => d.pages.walletOverview.interestReceived)}
                        value={holdingValue(totals?.interestReceived)}
                        sub={subLine(
                            t((d) => d.pages.walletOverview.interestReceivedHint),
                            totals?.interestReceived,
                            perCurrency,
                        )}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        title={t((d) => d.pages.walletOverview.byBankTitle)}
                        description={t((d) => d.pages.walletOverview.byBankHint)}
                        icon={<Landmark className="size-4" />}
                        /* How many, in the head — the first question a grouped list raises, answered before
                           the list is read rather than by counting its rows. */
                        aside={
                            overview === null || overview.banks.length === 0 ? undefined : (
                                <Pill>
                                    {t((d) => d.pages.walletOverview.activeCount).replace(
                                        '{count}',
                                        String(totals?.depositCount ?? overview.banks.length),
                                    )}
                                </Pill>
                            )
                        }
                        footActions={
                            <PanelButton
                                onClick={() => {
                                    router.visit(ACCOUNTS_PATH);
                                }}
                            >
                                <Landmark className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletOverview.viewAccounts)}
                            </PanelButton>
                        }
                    >
                        {loading ? (
                            <ListSkeleton />
                        ) : overview === null || overview.banks.length === 0 ? (
                            <EmptyState
                                icon={<Landmark className="size-5" />}
                                title={t((d) => d.pages.walletOverview.noDeposits)}
                                description={t((d) => d.pages.walletOverview.noDepositsHint)}
                                /* No action of its own: the card's foot already carries this exact button,
                                   whether the list is empty or not, and offering it twice reads as two
                                   different things that happen to share a name. */
                            />
                        ) : (
                            <ul className="divide-border divide-y">
                                {overview.banks.map((bank) => {
                                    const share =
                                        depositsWhole > 0 ? Math.max(0, bank.converted.amountMinor) / depositsWhole : 0;

                                    return (
                                        <li key={bank.bankId ?? 'none'} className="py-3 first:pt-0 last:pb-0">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="flex min-w-0 items-start gap-2.5">
                                                    {/* The bank's own code where it has one — the four letters
                                                        people recognise from a statement — else a bank mark. */}
                                                    <IconBadge tone="brand">
                                                        {bank.code === null ? (
                                                            <Landmark className="size-3.5" />
                                                        ) : (
                                                            <span className="badge-code">{bank.code}</span>
                                                        )}
                                                    </IconBadge>
                                                    <div className="min-w-0">
                                                        <div className="text-xs font-semibold">
                                                            {bank.name ?? t((d) => d.pages.walletOverview.noBank)}
                                                        </div>
                                                        <div className="note mt-0.5">
                                                            {[
                                                                bank.depositCount === 1
                                                                    ? t((d) => d.pages.walletOverview.bankDeposit)
                                                                    : t(
                                                                          (d) => d.pages.walletOverview.bankDeposits,
                                                                      ).replace('{count}', String(bank.depositCount)),
                                                                t((d) => d.pages.walletOverview.nextMaturity).replace(
                                                                    '{date}',
                                                                    formatDate(bank.nextMaturityAt, locale),
                                                                ),
                                                            ].join(' · ')}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="shrink-0 text-right tabular-nums">
                                                    <div className="text-sm font-semibold">
                                                        {converted(bank.converted)}
                                                    </div>
                                                    <div className="note">{perCurrency(bank.principal)}</div>
                                                    {Object.keys(bank.interestAtMaturity).length === 0 ? null : (
                                                        <div className="text-success note-xs">
                                                            {t((d) => d.pages.walletOverview.plusInterest).replace(
                                                                '{amount}',
                                                                perCurrency(bank.interestAtMaturity),
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="mt-2 flex items-center gap-2">
                                                <div className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                                                    <div
                                                        className="h-full rounded-full bg-[var(--brand-600)]"
                                                        style={{ width: `${String(share * 100)}%` }}
                                                    />
                                                </div>
                                                <span className="note w-9 text-right tabular-nums">
                                                    {percent(share)}
                                                </span>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Panel>

                    {/* Its own empty words: this card being empty means nothing is *due*, which is a
                        different thing from owning no deposits and reads as good news rather than absence. */}
                    <Panel
                        title={t((d) => d.pages.walletOverview.maturingTitle)}
                        description={t((d) => d.pages.walletOverview.maturingHint)}
                        icon={<CalendarClock className="size-4" />}
                        aside={
                            overview === null || overview.maturing.length === 0 ? undefined : (
                                <Pill>
                                    {t((d) => d.pages.walletOverview.upcomingCount).replace(
                                        '{count}',
                                        String(overview.maturing.length),
                                    )}
                                </Pill>
                            )
                        }
                    >
                        {loading ? (
                            <ListSkeleton />
                        ) : overview === null || overview.maturing.length === 0 ? (
                            <EmptyState
                                icon={<CalendarClock className="size-5" />}
                                title={t((d) => d.pages.walletOverview.nothingMaturing)}
                                description={t((d) => d.pages.walletOverview.nothingMaturingHint)}
                            />
                        ) : (
                            <ul className="divide-border divide-y">
                                {overview.maturing.map((deposit) => (
                                    <li
                                        key={deposit.id}
                                        className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                                    >
                                        <CalendarTile isoDate={deposit.maturityAt} locale={locale} />
                                        <div className="min-w-0 flex-1">
                                            <div className="text-xs font-semibold">{deposit.displayName}</div>
                                            <div className="note mt-0.5">
                                                {[
                                                    formatDate(deposit.maturityAt, locale),
                                                    deposit.daysToMaturity === null || deposit.daysToMaturity < 0
                                                        ? t((d) => d.pages.walletDeposits.matured)
                                                        : t((d) => d.pages.walletDeposits.daysLeft).replace(
                                                              '{days}',
                                                              String(deposit.daysToMaturity),
                                                          ),
                                                ].join(' · ')}
                                            </div>
                                        </div>
                                        {/* Two figures, each under its own name. Stacked with the interest as
                                            a footnote, the smaller number read as a qualifier of the larger
                                            one rather than as the other half of what comes back. */}
                                        <div className="flex shrink-0 gap-5 text-right tabular-nums">
                                            <div>
                                                <div className="note">
                                                    {t((d) => d.pages.walletOverview.principalLabel)}
                                                </div>
                                                <div className="mt-0.5 text-xs font-semibold">
                                                    {formatMinor(deposit.principalMinor, deposit.currencyCode, locale)}
                                                </div>
                                            </div>
                                            {deposit.interestAtMaturityMinor === null ? null : (
                                                <div>
                                                    <div className="note">
                                                        {t((d) => d.pages.walletOverview.grossInterestLabel)}
                                                    </div>
                                                    <div className="text-success mt-0.5 text-xs font-semibold">
                                                        {t((d) => d.pages.walletOverview.plusInterest).replace(
                                                            '{amount}',
                                                            formatMinor(
                                                                deposit.interestAtMaturityMinor,
                                                                deposit.currencyCode,
                                                                locale,
                                                            ),
                                                        )}
                                                        {deposit.annualRate === null ? '' : ` · ${deposit.annualRate}%`}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {/*
                        The four things somebody opens this screen to start. Your mockup's fourth was "New
                        declaration", which is a page that no longer exists — assessing a year is the act that
                        replaced it, and it is the one a dashboard can actually lead to today.
                    */}
                    <Panel
                        title={t((d) => d.pages.walletOverview.quickTitle)}
                        description={t((d) => d.pages.walletOverview.quickHint)}
                        icon={<Zap className="size-4" />}
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            {[
                                {
                                    icon: <Upload className="size-3.5" aria-hidden="true" />,
                                    label: t((d) => d.pages.walletOverview.quickUpload),
                                    path: DOCUMENTS_PATH,
                                },
                                {
                                    icon: <Plus className="size-3.5" aria-hidden="true" />,
                                    label: t((d) => d.pages.walletOverview.quickTransaction),
                                    path: TRANSACTIONS_PATH,
                                },
                                {
                                    icon: <Calculator className="size-3.5" aria-hidden="true" />,
                                    label: t((d) => d.pages.walletOverview.quickAssess),
                                    path: TAXES_PATH,
                                },
                                {
                                    icon: <RefreshCw className="size-3.5" aria-hidden="true" />,
                                    label: t((d) => d.pages.walletOverview.quickSpv),
                                    path: SPV_PATH,
                                },
                            ].map((action) => (
                                <button
                                    key={action.path}
                                    type="button"
                                    className="quick-action"
                                    onClick={() => {
                                        router.visit(action.path);
                                    }}
                                >
                                    <span className="quick-action-mark">{action.icon}</span>
                                    {action.label}
                                </button>
                            ))}
                        </div>
                    </Panel>

                    <FxHistoryCard locale={locale} />
                </div>
            </Page>
        </AppShell>
    );
}

/** The day and month a deposit matures, as a small calendar leaf — scanned down a list faster than a date. */
function CalendarTile({ isoDate, locale }: { isoDate: string; locale: string }): JSX.Element {
    const date = new Date(`${isoDate.slice(0, 10)}T00:00:00Z`);
    const part = (options: Intl.DateTimeFormatOptions): string => {
        try {
            return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(date);
        } catch {
            return '';
        }
    };

    return (
        <div className="border-border bg-muted/60 flex w-11 shrink-0 flex-col items-center rounded-lg border py-1 leading-none">
            <span className="text-base font-bold tabular-nums">{part({ day: 'numeric' })}</span>
            <span className="eyebrow mt-0.5">{part({ month: 'short' }).replace('.', '')}</span>
        </div>
    );
}

function ListSkeleton(): JSX.Element {
    return (
        <div className="space-y-2">
            {[0, 1, 2].map((index) => (
                <Skeleton key={index} className="tile h-10 w-full" />
            ))}
        </div>
    );
}

/** A hint, followed by the original currencies when the figure has any. */
function subLine(
    hint: string,
    holding: Holding | undefined,
    perCurrency: (amounts: Record<string, number>) => string,
): string {
    return holding === undefined || Object.keys(holding.amounts).length === 0
        ? hint
        : `${hint} · ${perCurrency(holding.amounts)}`;
}

/**
 * What the converted total is standing on: the newest rate it used, and whether anything was left
 * out for want of one. The newest date rather than one per currency — a stat line has room for one,
 * and the freshest rate is what a reader wonders about.
 */
function netWorthNote(netWorth: NetWorth, t: Translate): string {
    if (!netWorth.complete) {
        return t((d) => d.pages.walletOverview.netWorthPartial).replace('{currencies}', netWorth.missing.join(', '));
    }

    const newest = Object.values(netWorth.rates)
        .map((entry) => entry.rateDate)
        .filter((date) => date !== '')
        .sort()
        .at(-1);

    // No rates used means everything was already in the display currency; claiming one would be
    // inventing provenance.
    return newest === undefined
        ? t((d) => d.pages.walletOverview.netWorthDirect)
        : t((d) => d.pages.walletOverview.netWorthRate).replace('{date}', newest);
}

/**
 * A vertical line at the day under the cursor.
 *
 * Chart.js draws none, and without it the tooltip is a box floating over a line with nothing saying
 * which of sixty points it belongs to. Registered on this chart only — a plugin in the configuration
 * applies to its own instance, unlike `ChartJS.register`, which would put the line on every chart in
 * the app.
 */
const hoverLine: Plugin<'line'> = {
    id: 'hoverLine',
    afterDatasetsDraw(chart) {
        const hovered = chart.getActiveElements().at(0);

        if (hovered === undefined) {
            return;
        }

        const { ctx, chartArea } = chart;

        ctx.save();
        ctx.beginPath();
        ctx.moveTo(hovered.element.x, chartArea.top);
        ctx.lineTo(hovered.element.x, chartArea.bottom);
        ctx.lineWidth = 1;
        ctx.strokeStyle = themeColor('--muted-foreground', 0.5);
        ctx.stroke();
        ctx.restore();
    },
};

/**
 * What a currency has been worth, as a line.
 *
 * Its own component because it fetches on its own schedule — a currency and a window the reader chooses, not
 * the dashboard's one payload. Folding it into the page's state would reload the whole overview every time
 * somebody switched from EUR to USD.
 *
 * The rates are published BNR figures and belong to no taxpayer, which is why this asks `/v1/fx` rather than
 * anything under `{subject}`.
 */
function FxHistoryCard({ locale }: { locale: string }): JSX.Element {
    const { t } = useTranslation();
    /*
     * The installation's own currencies, less the base one — there is no rate of the leu against the
     * leu, and this chart is rates. It used to be a second hardcoded list, four codes that happened
     * to overlap with the five the wallet forms offered.
     */
    const offered = useCurrencies().filter((code) => code !== BASE_CURRENCY);
    const [currency, setCurrency] = useRememberedState<string>('dashboard.fx.currency', offered[0] ?? 'EUR', {
        isValid: (value: string) => offered.includes(value),
    });
    const [months, setMonths] = useRememberedState<string>('dashboard.fx.months', '3', {
        isValid: (value: string) => FX_WINDOWS.includes(value),
    });
    const [points, setPoints] = useState<{ date: string; rate: number }[] | null>(null);

    useEffect(() => {
        let current = true;

        void apiRequest(
            `/v1/fx/rates/history?currency=${encodeURIComponent(currency)}&months=${encodeURIComponent(months)}`,
        )
            .then((text) => {
                const body: unknown = JSON.parse(text);
                const raw = typeof body === 'object' && body !== null ? (body as { points?: unknown }).points : null;

                if (current) {
                    setPoints(
                        (Array.isArray(raw) ? raw : []).flatMap((entry): { date: string; rate: number }[] => {
                            const row = entry as { date?: unknown; rate?: unknown };

                            return typeof row.date === 'string' && typeof row.rate === 'string'
                                ? [{ date: row.date, rate: Number(row.rate) }]
                                : [];
                        }),
                    );
                }
            })
            .catch(() => {
                if (current) {
                    setPoints([]);
                }
            });

        return () => {
            current = false;
        };
    }, [currency, months]);

    const config = useMemo<ChartConfiguration>(() => {
        const rows = points ?? [];
        /*
         * The axis reads in words, the tooltip in full.
         *
         * The labels stay ISO, because that is what the tooltip's title formats and what the data is
         * keyed by; the axis gets its own short form through a tick callback. Three months of daily
         * rates is sixty-odd labels, so Chart.js is left to skip all but a handful.
         */
        const axisDates = rows.map((point) =>
            new Date(`${point.date}T00:00:00Z`).toLocaleDateString(locale, {
                day: 'numeric',
                month: 'short',
                timeZone: 'UTC',
            }),
        );

        return {
            type: 'line',
            data: {
                labels: rows.map((point) => point.date),
                datasets: [
                    {
                        data: rows.map((point) => point.rate),
                        // Blue, like the line on the reports page. `--primary` is a near-black in this
                        // theme, which put a black line and a black wash over grey gridlines — three
                        // greys with nothing to tell them apart.
                        borderColor: themeColor('--info'),
                        // A fill under the line, held well back: it says "this is a quantity over time"
                        // without competing with the line for the reader's eye.
                        backgroundColor: themeColor('--info', 0.12),
                        borderWidth: 2,
                        fill: true,
                        // The last point marked, because "where it is now" is the one value a reader is
                        // looking for in a line they are glancing at. The rest are drawn by the line.
                        pointRadius: rows.map((_, index, all) => (index === all.length - 1 ? 3 : 0)),
                        pointBackgroundColor: themeColor('--info'),
                        pointHoverRadius: 4,
                        tension: 0.35,
                    },
                ],
            },
            plugins: [hoverLine],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                /*
                 * Hovering anywhere over the chart reads the nearest day.
                 *
                 * Chart.js defaults to `intersect: true`, which shows the tooltip only while the cursor
                 * is inside a point — and these points are drawn at radius 0, so the target was a pixel
                 * wide and invisible. `index` on the x axis means the whole column counts, which is how
                 * a reader expects to use a line: move across it and read the values.
                 */
                interaction: { mode: 'index', intersect: false, axis: 'x' },
                plugins: {
                    legend: { display: false },
                    /*
                     * Only the words. `Chart.tsx` draws the box itself, in CSS, so nothing here says
                     * what it looks like — the date as the reader writes it, and the rate at the four
                     * decimals the BNR publishes.
                     */
                    tooltip: {
                        callbacks: {
                            title: (items) => formatDate(items[0]?.label ?? '', locale),
                            label: (item) => `${currency} ${(item.parsed.y ?? 0).toFixed(4)}`,
                        },
                    },
                },
                scales: {
                    // Dated, like every other line in the app: a rate chart with no axis is a shape,
                    // and the window the reader picked is the thing it is a shape of.
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: themeColor('--muted-foreground'),
                            font: { size: 10 },
                            autoSkip: true,
                            maxTicksLimit: 5,
                            maxRotation: 0,
                            callback: (_, index) => axisDates.at(index) ?? '',
                        },
                    },
                    y: {
                        // Not from zero: a rate that moves between 4.97 and 5.06 is a flat line above a
                        // zero axis, which hides the only thing the chart is for.
                        beginAtZero: false,
                        border: { display: false },
                        grid: { color: themeColor('--border') },
                        ticks: {
                            color: themeColor('--muted-foreground'),
                            font: { size: 10 },
                            maxTicksLimit: 4,
                            // Two decimals throughout, or the column reads `5`, `4.95`, `4.9` — three
                            // different precisions for one quantity.
                            callback: (value) => Number(value).toFixed(2),
                        },
                    },
                },
            },
        };
    }, [points, currency, locale]);

    const latest = points?.at(-1) ?? null;

    return (
        <Panel
            title={t((d) => d.pages.walletOverview.fxTitle)}
            description={t((d) => d.pages.walletOverview.fxHint)}
            icon={<ChartLine className="size-4" />}
            aside={
                <>
                    <PanelSelect
                        className="ctl-narrow"
                        value={currency}
                        onValueChange={setCurrency}
                        options={offered.map((code) => ({ value: code, label: code }))}
                    />
                    <PanelSelect
                        className="ctl-narrow"
                        value={months}
                        onValueChange={setMonths}
                        options={FX_WINDOWS.map((value) => ({
                            value,
                            label: t((d) => d.pages.walletOverview.fxWindow).replace('{months}', value),
                        }))}
                    />
                </>
            }
            footNote={
                latest === null ? undefined : (
                    <span className="note">
                        {t((d) => d.pages.walletOverview.fxLatest)
                            .replace('{currency}', currency)
                            .replace('{rate}', latest.rate.toFixed(4))
                            .replace('{date}', formatDate(latest.date, locale))}
                    </span>
                )
            }
        >
            {points === null ? (
                <Skeleton className="h-40 w-full" />
            ) : points.length === 0 ? (
                <EmptyState
                    icon={<ChartLine className="size-5" />}
                    title={t((d) => d.pages.walletOverview.fxEmpty)}
                    description={t((d) => d.pages.walletOverview.fxEmptyHint)}
                />
            ) : (
                <div className="chart-line-sm">
                    <Chart config={config} label={t((d) => d.pages.walletOverview.fxTitle)} />
                </div>
            )}
        </Panel>
    );
}
