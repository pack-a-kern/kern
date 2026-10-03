import { Head, router } from '@inertiajs/react';
import { Banknote, HandCoins, Landmark, PiggyBank, Receipt, TrendingUp, Wallet } from 'lucide-react';
import { useEffect, useState, type JSX, type ReactNode } from 'react';

import { getWalletOverview } from '../api/walletApi';
import AppShell from '../components/AppShell';
import { Page, Panel, PanelButton } from '../components/panel';
import { Skeleton } from '../components/ui/skeleton';
import { useTranslation } from '../i18n';
import { formatDate } from '../lib/format';
import { getActiveSubjectId } from '../lib/subjectStore';
import { cn } from '../lib/utils';
import { formatMinor, type Holding, type NetWorth, type WalletOverview as Overview } from '../types/wallet';

/**
 * Not a configurable choice: `deposit_interest_events.ron_equivalent_minor` is RON by definition —
 * the column exists because the value was converted at the BNR rate for its accrual date.
 */
const RECOGNISED_CURRENCY = 'RON';

/** Mirrors `routes/web.php`'s named route, so the path appears once on this screen. */
const ACCOUNTS_PATH = '/wallet/accounts';

type Translate = ReturnType<typeof useTranslation>['t'];

/** The colour an icon badge carries: what kind of figure sits beside it, never how good it is. */
type Tone = 'brand' | 'success' | 'amber' | 'info';

const TONE_CLASS: Record<Tone, string> = {
    brand: 'bg-accent text-accent-foreground',
    success: 'bg-success/10 text-success',
    amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    info: 'bg-info/10 text-info',
};

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
    const groups =
        totals === null
            ? []
            : GROUPS.map((group) => ({ ...group, holding: totals.holdings[group.key] })).filter(
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

                <section className="border-border bg-card shadow-float rounded-xl border p-5">
                    <div className="text-muted-foreground flex items-center gap-2.5 text-xs">
                        <IconBadge tone="brand">
                            <Wallet className="size-4" />
                        </IconBadge>
                        {t((d) => d.pages.walletOverview.netWorth)}
                    </div>
                    <div className="mt-3 text-3xl font-bold tracking-tight tabular-nums">
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
                    {totals === null ? null : <div className="note mt-1">{netWorthNote(totals.netWorth, t)}</div>}

                    {/* Where it is, as one bar: the question the four separate figures used to leave the
                        reader to work out by comparing numbers. */}
                    <div className="bg-muted mt-5 flex h-2.5 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
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

                    <dl className="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                        {groups.map((group) => (
                            <div key={group.key} className="min-w-0">
                                <dt className="text-muted-foreground flex items-center gap-2 text-xs">
                                    <span className={cn('size-2.5 shrink-0 rounded-full', group.swatch)} />
                                    {t((d) => d.pages.walletOverview[group.key])}
                                    <span className="ml-auto tabular-nums">{percent(shareOf(group.holding))}</span>
                                </dt>
                                <dd className="mt-1 text-sm font-semibold tabular-nums">
                                    {holdingValue(group.holding)}
                                </dd>
                                <dd className="note mt-0.5">
                                    {[
                                        group.key === 'deposits' && totals !== null
                                            ? t((d) => d.pages.walletOverview.depositCount).replace(
                                                  '{count}',
                                                  String(totals.depositCount),
                                              )
                                            : '',
                                        perCurrency(group.holding.amounts),
                                    ]
                                        .filter((part) => part !== '')
                                        .join(' · ')}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <p className="note border-border mt-4 border-t pt-3">
                        {t((d) => d.pages.walletOverview.generalNote)}
                    </p>
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
                                          holding: totals.holdings.general,
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
                    <Kpi
                        tone="info"
                        icon={<Receipt className="size-4" />}
                        label={t((d) => d.pages.walletOverview.recognisedIncome)}
                        // A dash, never zero, while nothing is computed: zero would claim income of zero.
                        value={
                            totals === null || totals.recognisedRonMinor === null
                                ? '—'
                                : formatMinor(totals.recognisedRonMinor, RECOGNISED_CURRENCY, locale)
                        }
                        sub={t((d) => d.pages.walletOverview.recognisedHint)}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        title={t((d) => d.pages.walletOverview.byBankTitle)}
                        description={t((d) => d.pages.walletOverview.byBankHint)}
                        icon={<Landmark className="size-4" />}
                        footActions={
                            <PanelButton
                                onClick={() => {
                                    router.visit(ACCOUNTS_PATH);
                                }}
                            >
                                {t((d) => d.pages.walletOverview.viewAccounts)}
                            </PanelButton>
                        }
                    >
                        {loading ? (
                            <ListSkeleton />
                        ) : overview === null || overview.banks.length === 0 ? (
                            <p className="note">{t((d) => d.pages.walletOverview.noDeposits)}</p>
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

                    <Panel
                        title={t((d) => d.pages.walletOverview.maturingTitle)}
                        description={t((d) => d.pages.walletOverview.maturingHint)}
                    >
                        {loading ? (
                            <ListSkeleton />
                        ) : overview === null || overview.maturing.length === 0 ? (
                            <p className="note">{t((d) => d.pages.walletOverview.noDeposits)}</p>
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
                                        <div className="shrink-0 text-right tabular-nums">
                                            <div className="text-sm font-semibold">
                                                {formatMinor(deposit.principalMinor, deposit.currencyCode, locale)}
                                            </div>
                                            {deposit.interestAtMaturityMinor === null ? null : (
                                                <div className="text-success note-xs">
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
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </Page>
        </AppShell>
    );
}

function IconBadge({ tone, children }: { tone: Tone; children: ReactNode }): JSX.Element {
    return (
        <span className={cn('grid size-8 shrink-0 place-items-center rounded-lg', TONE_CLASS[tone])}>{children}</span>
    );
}

/** One figure beside the headline: a coloured mark saying what kind it is, the value, and its detail. */
function Kpi(props: {
    tone: Tone;
    icon: ReactNode;
    label: string;
    value: string;
    sub?: string | undefined;
}): JSX.Element {
    return (
        <div className="border-border bg-card shadow-hair rounded-xl border p-4">
            <div className="text-muted-foreground flex items-center gap-2.5 text-xs">
                <IconBadge tone={props.tone}>{props.icon}</IconBadge>
                {props.label}
            </div>
            <div className="mt-3 text-xl font-bold tracking-tight tabular-nums">{props.value}</div>
            {props.sub === undefined || props.sub === '' ? null : <div className="note mt-1">{props.sub}</div>}
        </div>
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
