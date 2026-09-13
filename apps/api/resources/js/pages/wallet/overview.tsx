import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState, type JSX } from 'react';

import { getWalletOverview } from '../../api/walletApi';
import AppShell from '../../components/AppShell';
import { Page, Panel, PanelButton, Row, RowList, Stat, StatGrid } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { formatMinor, sourceName, type NetWorth, type WalletOverview as Overview } from '../../types/wallet';

/**
 * These mirror `components/AppShell.tsx`'s sidebar hrefs and `routes/web.php`'s named routes. Named
 * here rather than inlined at the two call sites so the strings appear once on this screen, and a
 * grep for the path finds every place that navigates to it.
 */
/**
 * Not a configurable choice: `deposit_interest_events.ron_equivalent_minor` is RON by definition —
 * the column exists because the value was converted at the BNR rate for its accrual date. The
 * constant names that rather than leaving a bare string in the markup.
 */
const RECOGNISED_CURRENCY = 'RON';

const ACCOUNTS_PATH = '/portofel/conturi';
const DEPOSITS_PATH = '/portofel/depozite';

/**
 * The wallet landing screen.
 *
 * One thing here is a deliberate refusal rather than a gap: there is no single "total balance"
 * figure. Balances are shown per currency, because adding RON to EUR needs a rate, a date and a
 * source — a fiscal decision the tax engine makes with provenance. A dashboard that printed one
 * number would be inventing an exchange rate and presenting it as fact, on a screen whose figures
 * end up on a tax return.
 *
 * The one number that *is* safe to total is "tax-relevant income": those rows were converted at the
 * BNR rate for their own accrual date, each carrying the source document version it came from.
 */
export default function WalletOverview(): JSX.Element {
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

    /** Per-currency totals rendered as one line each, so no conversion is implied. */
    const perCurrency = useCallback(
        (amounts: Record<string, number>): string =>
            Object.entries(amounts).length === 0
                ? '—'
                : Object.entries(amounts)
                      .map(([currency, minor]) => formatMinor(minor, currency, locale))
                      .join(' · '),
        [locale],
    );

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.walletOverview.title)}>
                    <Panel title={t((d) => d.pages.walletOverview.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    const totals = overview?.totals ?? null;

    return (
        <AppShell>
            <Page title={t((d) => d.pages.walletOverview.title)} lede={t((d) => d.pages.walletOverview.description)}>
                <StatGrid>
                    {/* One number rather than "€444.00 · RON 600,010.00", which answers no question
                        anyone actually has. The rate and its publication date sit underneath it,
                        because a converted total without them is a claim nobody can check — and it
                        is an indication for a dashboard, never a figure a tax is computed from. */}
                    <Stat
                        label={t((d) => d.pages.walletOverview.netWorth)}
                        value={totals === null ? '—' : formatMinor(totals.netWorth.amountMinor, totals.netWorth.currencyCode, locale)}
                        sub={totals === null ? undefined : netWorthNote(totals.netWorth, t)}
                    />
                    <Stat
                        label={t((d) => d.pages.walletOverview.totalBalance)}
                        value={totals === null ? '—' : perCurrency(totals.balances)}
                        sub={
                            totals === null
                                ? undefined
                                : t((d) => d.pages.walletOverview.acrossAccounts).replace(
                                      '{count}',
                                      String(totals.accounts),
                                  )
                        }
                    />
                    <Stat
                        label={t((d) => d.pages.walletOverview.deposits)}
                        value={totals === null ? '—' : perCurrency(totals.deposits)}
                        sub={
                            totals === null
                                ? undefined
                                : t((d) => d.pages.walletOverview.depositCount).replace(
                                      '{count}',
                                      String(totals.depositCount),
                                  )
                        }
                    />
                    <Stat
                        label={t((d) => d.pages.walletOverview.interestAtMaturity)}
                        value={totals === null ? '—' : perCurrency(totals.interestAtMaturity)}
                        sub={t((d) => d.pages.walletOverview.grossAccrued)}
                    />
                    <Stat
                        label={t((d) => d.pages.walletOverview.recognisedIncome)}
                        value={
                            totals === null ? '—' : formatMinor(totals.recognisedRonMinor, RECOGNISED_CURRENCY, locale)
                        }
                        sub={t((d) => d.pages.walletOverview.recognisedHint)}
                    />
                </StatGrid>

                <Panel
                    title={t((d) => d.pages.walletOverview.accountsTitle)}
                    description={t((d) => d.pages.walletOverview.accountsHint)}
                    footNote={t((d) => d.pages.wallet.perCurrencyNote)}
                    footActions={
                        <PanelButton
                            onClick={() => {
                                router.visit(ACCOUNTS_PATH);
                            }}
                        >
                            {t((d) => d.pages.walletOverview.viewAll)}
                        </PanelButton>
                    }
                >
                    {overview === null ? (
                        failed ? (
                            <p className="note">{t((d) => d.pages.wallet.loadFailed)}</p>
                        ) : (
                            <ListSkeleton />
                        )
                    ) : overview.accounts.length === 0 ? (
                        <p className="note">{t((d) => d.pages.walletOverview.noAccounts)}</p>
                    ) : (
                        <RowList>
                            {overview.accounts.map((account) => (
                                <Row
                                    key={account.id}
                                    title={sourceName(account, t((d) => d.pages.wallet.generalSource))}
                                    subtitle={t((d) => d.pages.wallet.kinds[account.kind] ?? account.kind)}
                                >
                                    {/* One line per currency: a source may hold several, and a
                                        single figure could only be right by accident. */}
                                    <div className="text-right tabular-nums">
                                        {account.balances.length === 0 ? (
                                            <span className="note">{t((d) => d.pages.wallet.unknownBalance)}</span>
                                        ) : (
                                            account.balances.map((balance) => (
                                                <div key={balance.currencyCode}>
                                                    {formatMinor(balance.amountMinor, balance.currencyCode, locale)}
                                                </div>
                                            ))
                                        )}
                                    </div>
                                </Row>
                            ))}
                        </RowList>
                    )}
                </Panel>

                <Panel
                    title={t((d) => d.pages.walletOverview.maturingTitle)}
                    description={t((d) => d.pages.walletOverview.maturingHint)}
                    footActions={
                        <PanelButton
                            onClick={() => {
                                router.visit(DEPOSITS_PATH);
                            }}
                        >
                            {t((d) => d.pages.walletOverview.viewDeposits)}
                        </PanelButton>
                    }
                >
                    {overview === null ? (
                        failed ? (
                            <p className="note">{t((d) => d.pages.wallet.loadFailed)}</p>
                        ) : (
                            <ListSkeleton />
                        )
                    ) : overview.maturing.length === 0 ? (
                        <p className="note">{t((d) => d.pages.walletOverview.noMaturing)}</p>
                    ) : (
                        <RowList>
                            {overview.maturing.map((deposit) => (
                                <Row
                                    key={deposit.id}
                                    title={deposit.displayName}
                                    subtitle={new Date(deposit.maturityAt).toLocaleDateString()}
                                >
                                    <div className="text-right">
                                        <div className="tabular-nums">
                                            {formatMinor(deposit.principalMinor, deposit.currencyCode, locale)}
                                        </div>
                                        <div className="note">
                                            {deposit.annualRate === null
                                                ? t((d) => d.pages.walletDeposits.noRate)
                                                : `${deposit.annualRate}% p.a.`}
                                        </div>
                                    </div>
                                </Row>
                            ))}
                        </RowList>
                    )}
                </Panel>
            </Page>
        </AppShell>
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

/**
 * What the converted total is standing on: the newest rate it used, and whether anything was left
 * out for want of one.
 *
 * The *newest* date rather than one per currency: a stat line has room for one, and the freshest
 * rate is what a reader is really asking about when they wonder how current the figure is. The full
 * set comes back in `rates` for a screen that wants to show them all.
 */
function netWorthNote(netWorth: NetWorth, t: ReturnType<typeof useTranslation>['t']): string {
    if (!netWorth.complete) {
        return t((d) => d.pages.walletOverview.netWorthPartial).replace('{currencies}', netWorth.missing.join(', '));
    }

    const dates = Object.values(netWorth.rates)
        .map((entry) => entry.rateDate)
        .filter((date) => date !== '')
        .sort();

    const newest = dates.at(-1);

    // No rates used at all means everything was already in the display currency — there is nothing
    // to attribute, and claiming a rate would be inventing provenance.
    return newest === undefined
        ? t((d) => d.pages.walletOverview.netWorthDirect)
        : t((d) => d.pages.walletOverview.netWorthRate).replace('{date}', newest);
}
