import { Head, router } from '@inertiajs/react';
import {
    Banknote,
    Calculator,
    CalendarClock,
    ChartLine,
    CirclePlus,
    CloudDownload,
    FileUp,
    HandCoins,
    Hourglass,
    Info,
    Landmark,
    Percent,
    PiggyBank,
    TrendingUp,
    UserRoundPlus,
    Wallet,
    Zap,
} from 'lucide-react';
import { useEffect, useMemo, useState, type JSX, type ReactNode } from 'react';
import type { ChartConfiguration } from 'chart.js';

import { getWalletOverview } from '../api/walletApi';
import { apiRequest } from '../lib/http';
import BankMark from '../components/wallet/BankMark';
import { Chart } from '../components/Chart';
import { EmptyState, IconBadge, Kpi, Page, Panel, PanelButton, PanelSelect, Pill } from '../components/panel';
import { Skeleton } from '../components/ui/skeleton';
import { useRememberedState } from '../hooks/useRememberedState';
import { useTranslation } from '../i18n';
import type { Dictionary } from '../i18n/dictionary';
import { formatDate, formatMinor, formatPercent } from '../lib/format';
import { Money } from '../components/Money';
import { useActiveSubjectId } from '../hooks/useActiveSubjectId';
import { useCurrencies } from '../lib/sharedProps';
import { hoverLine } from '../lib/chartHoverLine';
import { themeColor } from '../lib/themeColor';
import { cn } from '../lib/utils';
import {
    BASE_CURRENCY,
    type Holding,
    type HoldingGroup,
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
const SWATCHES: Record<HoldingGroup['group'], string> = {
    deposits: 'bg-[var(--brand-600)]',
    general: 'bg-[var(--brand-300)]',
    cash: 'bg-success',
    other: 'bg-amber-500',
    investments: 'bg-[var(--primary)]',
};

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
    const subjectId = useActiveSubjectId();

    const [overview, setOverview] = useState<Overview | null>(null);
    const [failed, setFailed] = useState(false);
    // The allocation card: which holding is in focus (null = the largest, chosen below), and whether
    // the "how it works" note is open.
    const [focusGroup, setFocusGroup] = useState<string | null>(null);
    const [howOpen, setHowOpen] = useState(false);

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
        // No taxpayer yet — the dashboard has nothing to add up, so it leads with the one thing to
        // do first rather than an empty shell. The taxpayer-scoped menus are hidden to match
        // ({@link AppShell}'s `requiresSubject`).
        return (
            <>
                <Head title={t((d) => d.dashboard.title)} />
                <Page title={t((d) => d.dashboard.title)} lede={t((d) => d.dashboard.lede)}>
                    <div className="bg-card mx-auto mt-2 flex w-full max-w-md flex-col items-center rounded-2xl border px-8 py-10 text-center shadow-sm">
                        <div className="bg-primary/10 text-primary grid size-14 place-items-center rounded-2xl">
                            <UserRoundPlus className="size-7" aria-hidden="true" />
                        </div>
                        <h2 className="mt-5 text-lg font-semibold tracking-tight text-balance">
                            {t((d) => d.dashboard.emptyTitle)}
                        </h2>
                        <p className="text-muted-foreground mt-2 max-w-sm text-sm leading-6">
                            {t((d) => d.dashboard.emptyBody)}
                        </p>
                        <PanelButton
                            primary
                            className="mt-6"
                            onClick={() => {
                                router.visit('/taxpayers');
                            }}
                        >
                            <CirclePlus className="size-3.5" aria-hidden="true" />
                            {t((d) => d.dashboard.emptyAction)}
                        </PanelButton>
                        <ul className="mt-8 grid w-full gap-3 border-t pt-6 text-left">
                            {(
                                [
                                    [Wallet, (d: Dictionary) => d.dashboard.emptyWallet],
                                    [PiggyBank, (d: Dictionary) => d.dashboard.emptyDeposits],
                                    [Calculator, (d: Dictionary) => d.dashboard.emptyTax],
                                ] as const
                            ).map(([Icon, label], index) => (
                                <li key={index} className="text-muted-foreground flex items-center gap-3 text-sm">
                                    <span className="bg-muted text-foreground grid size-8 shrink-0 place-items-center rounded-lg">
                                        <Icon className="size-4" aria-hidden="true" />
                                    </span>
                                    {t(label)}
                                </li>
                            ))}
                        </ul>
                    </div>
                </Page>
            </>
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

    const percent = (share: number): string => formatPercent(share, locale, 0);

    /** A holding's converted figure, or zero in the display currency when it holds nothing. */
    const holdingValue = (holding: Holding | undefined): string =>
        holding === undefined
            ? '—'
            : Object.keys(holding.amounts).length === 0
              ? formatMinor(0, holding.converted.currencyCode, locale)
              : converted(holding.converted);

    /** The same figure where it is a headline: the whole part large, the bani and currency smaller. */
    const holdingFigure = (holding: Holding | undefined): ReactNode =>
        holding === undefined ? (
            '—'
        ) : (
            <Money
                minor={Object.keys(holding.amounts).length === 0 ? 0 : holding.converted.amountMinor}
                currency={holding.converted.currencyCode}
            />
        );

    /**
     * The original amounts under a converted one — only when they say something it does not: another
     * currency, or several. One holding in the display currency is the figure above it twice.
     */
    const originals = (amounts: Record<string, number>, shown: NetWorth): string | null => {
        const currencies = Object.keys(amounts);

        return currencies.length === 1 && currencies[0] === shown.currencyCode ? null : perCurrency(amounts);
    };

    // Where the money is, each part with its share of the whole — grouped and divided by the server.
    const groups = totals?.breakdown ?? [];
    const owedToMe = totals?.owedToMe;

    // The holding under the spotlight: the one picked, else the largest share, so the card opens on
    // something rather than nothing. Its reading — the deposit count, the currencies — sits beneath.
    const largestGroup = groups.reduce<HoldingGroup | null>(
        (best, group) => (best === null || Number(group.share) > Number(best.share) ? group : best),
        null,
    );
    const focusKey = focusGroup ?? largestGroup?.group ?? null;
    const focused = groups.find((group) => group.group === focusKey) ?? null;
    const groupDetail = (group: HoldingGroup): string =>
        [
            group.group === 'deposits' && totals !== null
                ? t((d) => d.pages.walletOverview.depositCount).replace('{count}', String(totals.depositCount))
                : '',
            perCurrency(group.amounts),
        ]
            .filter((part) => part !== '')
            .join(' · ');

    return (
        <>
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
                    <div className="grid gap-6 p-5 lg:grid-cols-2 lg:gap-0">
                        {/* LEFT: the figure, where it came from, and the allocation it breaks into. */}
                        <div className="border-border flex min-w-0 flex-col lg:border-r lg:pr-6">
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
                                    <Money
                                        minor={totals.netWorth.amountMinor}
                                        currency={totals.netWorth.currencyCode}
                                    />
                                )}
                            </div>

                            {/* Where the figure came from, as a mark rather than a sentence. */}
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

                            {totals?.recordsShortFrom == null ? null : (
                                <p className="note mt-2">
                                    {t((d) => d.pages.walletOverview.recordsShortFrom).replace(
                                        '{date}',
                                        formatDate(totals.recordsShortFrom, locale),
                                    )}
                                </p>
                            )}

                            {/* Allocation — the bar, pinned to the foot of the column, and the one holding
                                in focus read out beneath it. The bar dims every share but the focused one. */}
                            {groups.length > 0 && (
                                <div className="mt-6 lg:mt-auto lg:pt-6">
                                    <div className="mb-2.5 flex items-center justify-between gap-2">
                                        <span className="text-xs font-semibold">
                                            {t((d) => d.pages.walletOverview.allocation)}
                                        </span>
                                        <span className="note">{t((d) => d.pages.walletOverview.allocationOf)}</span>
                                    </div>

                                    <div
                                        className="bg-muted relative flex h-1.5 w-full gap-0.5 overflow-hidden rounded-full"
                                        aria-hidden="true"
                                    >
                                        {groups.map((group) =>
                                            Number(group.share) === 0 ? null : (
                                                <div
                                                    key={group.group}
                                                    className={cn(
                                                        'h-full min-w-0 rounded-sm transition-[opacity,filter] duration-200',
                                                        SWATCHES[group.group],
                                                        focusKey !== null && focusKey !== group.group && 'opacity-25',
                                                        focusKey === group.group && 'brightness-110',
                                                    )}
                                                    style={{ width: `calc(${group.share} * 100%)` }}
                                                />
                                            ),
                                        )}
                                    </div>

                                    {focused !== null && (
                                        <div
                                            className="border-border mt-3 flex items-center gap-2.5 rounded-lg border px-3 py-2"
                                            aria-live="polite"
                                        >
                                            <span
                                                className={cn(
                                                    'size-2.5 shrink-0 rounded-full',
                                                    SWATCHES[focused.group],
                                                )}
                                                aria-hidden="true"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center justify-between gap-2 text-xs font-semibold">
                                                    <span className="truncate">
                                                        {t((d) => d.pages.walletOverview[focused.group])}
                                                    </span>
                                                    <span className="shrink-0 tabular-nums">
                                                        {percent(Number(focused.share))} · {holdingValue(focused)}
                                                    </span>
                                                </div>
                                                {groupDetail(focused) === '' ? null : (
                                                    <div className="note mt-0.5 truncate">{groupDetail(focused)}</div>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* RIGHT: a tile per holding; picking one puts it in focus on the left. */}
                        <div className="min-w-0 lg:pl-6">
                            <div className="mb-3 flex items-center justify-between gap-2">
                                <div className="panel-title">{t((d) => d.pages.walletOverview.whereItSits)}</div>
                                <span className="note">{t((d) => d.pages.walletOverview.selectCategory)}</span>
                            </div>

                            <div className="grid grid-cols-2 gap-2.5">
                                {groups.map((group) => {
                                    const active = focusKey === group.group;
                                    const zero = Number(group.share) === 0;

                                    return (
                                        <button
                                            key={group.group}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => {
                                                setFocusGroup(group.group);
                                            }}
                                            className={cn(
                                                'group border-border bg-card rounded-xl border p-3 text-left transition',
                                                'hover:border-accent-400 hover:-translate-y-px',
                                                'focus-visible:ring-accent-500/40 focus-visible:ring-2 focus-visible:outline-none',
                                                active && 'border-accent-500 bg-accent/30',
                                            )}
                                        >
                                            <div className="flex items-center justify-between gap-1.5">
                                                <span className="flex min-w-0 items-center gap-2 text-xs font-semibold">
                                                    <span
                                                        className={cn(
                                                            'size-2 shrink-0 rounded-full',
                                                            SWATCHES[group.group],
                                                        )}
                                                    />
                                                    <span className="truncate">
                                                        {t((d) => d.pages.walletOverview[group.group])}
                                                    </span>
                                                </span>
                                                <span className="note shrink-0 font-semibold tabular-nums">
                                                    {percent(Number(group.share))}
                                                </span>
                                            </div>
                                            <div
                                                className={cn(
                                                    'mt-3 text-base font-bold tabular-nums',
                                                    zero && 'text-muted-foreground',
                                                )}
                                            >
                                                {holdingValue(group)}
                                            </div>
                                            {groupDetail(group) === '' ? null : (
                                                <div className="note mt-1 truncate">{groupDetail(group)}</div>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    {/* FOOT: how the figure is reached, a sentence away behind a toggle. */}
                    <div className="border-border bg-muted/30 border-t px-5 py-3">
                        <div className="flex items-center justify-between gap-3">
                            <span className="note flex items-center gap-2">
                                <Info className="size-3.5 shrink-0" aria-hidden="true" />
                                {t((d) => d.pages.walletOverview.howTitle)}
                            </span>
                            <button
                                type="button"
                                aria-expanded={howOpen}
                                onClick={() => {
                                    setHowOpen((open) => !open);
                                }}
                                className="text-accent-700 dark:text-accent-400 shrink-0 text-xs font-semibold hover:underline"
                            >
                                {howOpen
                                    ? t((d) => d.pages.walletOverview.howHide)
                                    : t((d) => d.pages.walletOverview.howItWorks)}
                            </button>
                        </div>
                        {howOpen && (
                            <p className="note border-border mt-2.5 max-w-prose border-t pt-2.5">
                                {t((d) => d.pages.walletOverview.generalNote)}
                            </p>
                        )}
                    </div>
                </section>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(13rem,1fr))] gap-3">
                    {/* What can be spent today, as one figure: General and cash are both money to hand.
                        Each part stays named underneath. */}
                    <Kpi
                        tone="success"
                        icon={<Banknote className="size-4" />}
                        label={t((d) => d.pages.walletOverview.available)}
                        value={holdingFigure(totals?.holdings.available)}
                        sub={
                            totals === null
                                ? undefined
                                : [
                                      {
                                          label: t((d) => d.pages.walletOverview.general),
                                          holding:
                                              groups.find((group) => group.group === 'general') ??
                                              totals.holdings.general,
                                      },
                                      { label: t((d) => d.pages.walletOverview.cash), holding: totals.holdings.cash },
                                  ]
                                      .filter((part) => Object.keys(part.holding.amounts).length > 0)
                                      .map((part) => `${part.label} ${perCurrency(part.holding.amounts)}`)
                                      .join(' · ')
                        }
                    />

                    {/* Only once something is lent: a permanent zero would read as a debt being tracked. */}
                    {owedToMe === undefined || Object.keys(owedToMe.amounts).length === 0 ? null : (
                        <Kpi
                            tone="amber"
                            icon={<HandCoins className="size-4" />}
                            label={t((d) => d.pages.walletOverview.owedToMe)}
                            value={holdingFigure(owedToMe)}
                            sub={[t((d) => d.pages.walletOverview.owedToMeHint), perCurrency(owedToMe.amounts)].join(
                                ' · ',
                            )}
                        />
                    )}
                    <Kpi
                        tone="brand"
                        icon={<TrendingUp className="size-4" />}
                        label={t((d) => d.pages.walletOverview.interestAtMaturity)}
                        value={holdingFigure(totals?.interestAtMaturity)}
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
                        value={holdingFigure(totals?.interestReceived)}
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
                            <ul className="@container divide-border divide-y">
                                {overview.banks.map((bank) => {
                                    return (
                                        <li key={bank.bankId ?? 'none'} className="py-3 first:pt-0 last:pb-0">
                                            {/*
                                                One grid, two arrangements, by the card's own width (`@lg`,
                                                32rem), not the screen's — on a desktop this card is half the page.

                                                Narrow: the mark beside the name and its summary, centred on
                                                them; the figures under both, spread across the whole width
                                                rather than stacked in a corner; the share bar under those.
                                                Wide: the figures move to the name's right, and the bar runs
                                                under the two.
                                            */}
                                            <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 @lg:grid-cols-[auto_minmax(0,1fr)_auto] @lg:items-start">
                                                {/* The bank's logo where this installation has one; else its
                                                    own code — the four letters people recognise from a
                                                    statement — and else a bank mark. */}
                                                <div className="@lg:row-span-2">
                                                    {bank.logoUrl !== null && bank.name !== null ? (
                                                        <BankMark
                                                            bank={{
                                                                name: bank.name,
                                                                code: bank.code,
                                                                active: true,
                                                                logoUrl: bank.logoUrl,
                                                            }}
                                                            size="lg"
                                                        />
                                                    ) : bank.code === null ? (
                                                        <IconBadge tone="brand">
                                                            <Landmark className="size-3.5" />
                                                        </IconBadge>
                                                    ) : (
                                                        <span className="bank-mark">{bank.code}</span>
                                                    )}
                                                </div>
                                                <div className="min-w-0">
                                                    <div className="text-xs leading-5 font-semibold">
                                                        {bank.name ?? t((d) => d.pages.walletOverview.noBank)}
                                                    </div>
                                                    <div className="note mt-0.5">
                                                        {[
                                                            bank.depositCount === 1
                                                                ? t((d) => d.pages.walletOverview.bankDeposit)
                                                                : t((d) => d.pages.walletOverview.bankDeposits).replace(
                                                                      '{count}',
                                                                      String(bank.depositCount),
                                                                  ),
                                                            t((d) => d.pages.walletOverview.nextMaturity).replace(
                                                                '{date}',
                                                                formatDate(bank.nextMaturityAt, locale),
                                                            ),
                                                        ].join(' · ')}
                                                    </div>
                                                </div>
                                                <div className="col-span-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 tabular-nums @lg:col-span-1 @lg:flex-col @lg:items-end @lg:justify-start @lg:gap-0">
                                                    <div className="text-sm font-semibold">
                                                        <Money
                                                            minor={bank.converted.amountMinor}
                                                            currency={bank.converted.currencyCode}
                                                        />
                                                    </div>
                                                    {originals(bank.principal, bank.converted) === null ? null : (
                                                        <div className="note">
                                                            {originals(bank.principal, bank.converted)}
                                                        </div>
                                                    )}
                                                    {Object.keys(bank.interestAtMaturity).length === 0 ? null : (
                                                        <div className="text-success note-xs">
                                                            {t((d) => d.pages.walletOverview.plusInterest).replace(
                                                                '{amount}',
                                                                perCurrency(bank.interestAtMaturity),
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="col-span-2 flex items-center gap-2 @lg:col-start-2">
                                                    <div className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                                                        <div
                                                            className="h-full rounded-full bg-[var(--brand-600)]"
                                                            style={{ width: `calc(${bank.share} * 100%)` }}
                                                        />
                                                    </div>
                                                    <span className="note w-9 text-right tabular-nums">
                                                        {percent(Number(bank.share))}
                                                    </span>
                                                </div>
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
                            <ul className="@container divide-border divide-y">
                                {overview.maturing.map((deposit) => (
                                    <li
                                        key={deposit.id}
                                        className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 py-2.5 first:pt-0 last:pb-0 @lg:grid-cols-[auto_minmax(0,1fr)_auto]"
                                    >
                                        {/* The same arrangement as the bank rows above: narrow, the leaf beside
                                            the name and the two figures under both, one at each edge, so the
                                            row has no empty right half; wide, the figures at the name's right. */}
                                        <CalendarTile isoDate={deposit.maturityAt} locale={locale} />
                                        {/* Two lines, each with what it reads on the left and a badge at the
                                            right edge: the name and its rate, the date and the days left. */}
                                        <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2 gap-y-1">
                                            <span className="text-xs font-semibold">{deposit.displayName}</span>
                                            {deposit.annualRate === null ? (
                                                <span />
                                            ) : (
                                                <Pill tone="accent" className="justify-self-end">
                                                    <Percent className="size-3" aria-hidden="true" />
                                                    {deposit.annualRate}
                                                </Pill>
                                            )}
                                            <span className="note">{formatDate(deposit.maturityAt, locale)}</span>
                                            <Pill className="justify-self-end">
                                                <Hourglass className="size-3" aria-hidden="true" />
                                                {deposit.daysToMaturity === null || deposit.daysToMaturity < 0
                                                    ? t((d) => d.pages.walletDeposits.matured)
                                                    : t((d) => d.pages.walletDeposits.daysLeft).replace(
                                                          '{days}',
                                                          String(deposit.daysToMaturity),
                                                      )}
                                            </Pill>
                                        </div>
                                        {/* Two figures, each under its own name. Stacked with the interest as
                                            a footnote, the smaller number read as a qualifier of the larger
                                            one rather than as the other half of what comes back. */}
                                        <div className="col-span-2 flex justify-between gap-x-5 tabular-nums @lg:col-span-1 @lg:justify-end @lg:text-right">
                                            <div>
                                                <div className="note flex items-center gap-1 @lg:justify-end">
                                                    <Banknote className="size-3" aria-hidden="true" />
                                                    {t((d) => d.pages.walletOverview.principalLabel)}
                                                </div>
                                                <div className="mt-0.5 text-xs font-semibold">
                                                    {formatMinor(deposit.principalMinor, deposit.currencyCode, locale)}
                                                </div>
                                            </div>
                                            {deposit.interestAtMaturityMinor === null ? null : (
                                                <div className="text-right">
                                                    <div className="note flex items-center justify-end gap-1">
                                                        <TrendingUp className="size-3" aria-hidden="true" />
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
                        <div className="grid grid-cols-2 gap-2">
                            {[
                                {
                                    icon: <FileUp className="size-3.5" aria-hidden="true" />,
                                    tone: 'info',
                                    label: t((d) => d.pages.walletOverview.quickUpload),
                                    path: DOCUMENTS_PATH,
                                },
                                {
                                    icon: <CirclePlus className="size-3.5" aria-hidden="true" />,
                                    tone: 'success',
                                    label: t((d) => d.pages.walletOverview.quickTransaction),
                                    path: TRANSACTIONS_PATH,
                                },
                                {
                                    icon: <Calculator className="size-3.5" aria-hidden="true" />,
                                    tone: 'warning',
                                    label: t((d) => d.pages.walletOverview.quickAssess),
                                    path: TAXES_PATH,
                                },
                                {
                                    icon: <CloudDownload className="size-3.5" aria-hidden="true" />,
                                    tone: 'brand',
                                    label: t((d) => d.pages.walletOverview.quickSpv),
                                    path: SPV_PATH,
                                },
                            ].map((action) => (
                                <button
                                    key={action.path}
                                    type="button"
                                    className="quick-action"
                                    data-tone={action.tone}
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
        </>
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
