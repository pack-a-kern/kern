import { Head } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    CalendarRange,
    Coins,
    Hash,
    Landmark,
    WalletCards,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties, type JSX } from 'react';

import { apiRequest } from '../../lib/http';
import BankMark from '../../components/wallet/BankMark';
import { CellLabel, DataTable, EmptyState, Page, Panel, Stat, StatGrid, TableSkeleton } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useActiveSubjectId } from '../../hooks/useActiveSubjectId';
import { formatCompact, formatDate, formatMinor, formatPercent } from '../../lib/format';
import { nullableStr, num, obj, str } from '../../lib/json';

/** What moved each way through a bank in one currency. */
interface Flow {
    currencyCode: string;
    inMinor: number;
    outMinor: number;
    count: number;
}

/** One bank the ledger names, with the figures that make it a rundown rather than a list. */
interface BankRow {
    bankId: string | null;
    /** Null for the unidentified bucket, or a bank the register no longer lists by that id. */
    name: string | null;
    code: string | null;
    active: boolean;
    logoUrl: string | null;
    transactionCount: number;
    accountCount: number;
    firstBookedAt: string | null;
    lastBookedAt: string | null;
    flows: Flow[];
}

interface Totals {
    bankCount: number;
    transactionCount: number;
    identifiedCount: number;
    unidentifiedCount: number;
}

interface Rundown {
    banks: BankRow[];
    totals: Totals;
}

function parseFlow(entry: unknown): Flow {
    const row = obj(entry);

    return {
        currencyCode: str(row, 'currency_code'),
        inMinor: num(row, 'in_minor'),
        outMinor: num(row, 'out_minor'),
        count: num(row, 'count'),
    };
}

function parseRundown(payload: unknown): Rundown {
    const body = obj(payload);
    const totals = obj(body.totals);

    return {
        banks: (Array.isArray(body.banks) ? body.banks : []).map((entry): BankRow => {
            const row = obj(entry);

            return {
                bankId: nullableStr(row, 'bank_id'),
                name: nullableStr(row, 'name'),
                code: nullableStr(row, 'code'),
                active: row.active !== false,
                logoUrl: nullableStr(row, 'logo_url'),
                transactionCount: num(row, 'transaction_count'),
                accountCount: num(row, 'account_count'),
                firstBookedAt: nullableStr(row, 'first_booked_at'),
                lastBookedAt: nullableStr(row, 'last_booked_at'),
                flows: (Array.isArray(row.flows) ? row.flows : []).map(parseFlow),
            };
        }),
        totals: {
            bankCount: num(totals, 'bank_count'),
            transactionCount: num(totals, 'transaction_count'),
            identifiedCount: num(totals, 'identified_count'),
            unidentifiedCount: num(totals, 'unidentified_count'),
        },
    };
}

export default function WalletBanks(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = useActiveSubjectId();

    const [rundown, setRundown] = useState<Rundown | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/banks/activity`).then(
            (text: string) => {
                if (active) {
                    setRundown(parseRundown(JSON.parse(text)));
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

    const title = t((d) => d.pages.walletBanks.title);
    const total = rundown?.totals.transactionCount ?? 0;

    /** The same amount shortened for a glance, exact on hover — the bargain `formatCompact` requires. */
    const figure = (minor: number, code: string): JSX.Element => (
        <span title={formatMinor(minor, code, locale)}>
            {formatCompact(minor / 100, locale)} {code}
        </span>
    );

    /** How many of all this taxpayer's movements a bank carries. */
    const shareNote = (count: number): string =>
        total === 0
            ? ''
            : t((d) => d.pages.walletBanks.ofTransactions).replace('{share}', formatPercent(count / total, locale));

    const body = ((): JSX.Element => {
        if (failed) {
            return (
                <Panel title={title}>
                    <EmptyState
                        icon={<Landmark className="size-5" />}
                        title={t((d) => d.pages.walletBanks.loadFailed)}
                    />
                </Panel>
            );
        }

        if (rundown === null) {
            return (
                <Panel title={title} flush>
                    <TableSkeleton rows={5} />
                </Panel>
            );
        }

        const { banks, totals } = rundown;
        // Distinct across every bank's flows: the same currency at two banks is one currency here.
        const currencyCount = new Set(banks.flatMap((bank) => bank.flows.map((flow) => flow.currencyCode))).size;

        return (
            <>
                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.walletBanks.banksTile)}
                        value={totals.bankCount}
                        icon={<Landmark className="size-4" aria-hidden="true" />}
                    />
                    <Stat
                        label={t((d) => d.pages.walletBanks.transactionsTile)}
                        value={totals.transactionCount}
                        icon={<ArrowLeftRight className="size-4" aria-hidden="true" />}
                    />
                    <Stat
                        label={t((d) => d.pages.walletBanks.linkedTile)}
                        value={
                            totals.transactionCount === 0
                                ? '—'
                                : formatPercent(totals.identifiedCount / totals.transactionCount, locale)
                        }
                        sub={t((d) => d.pages.walletBanks.linkedSub).replace(
                            '{count}',
                            String(totals.unidentifiedCount),
                        )}
                        icon={<WalletCards className="size-4" aria-hidden="true" />}
                        tone="accent"
                    />
                    <Stat
                        label={t((d) => d.pages.walletBanks.currenciesTile)}
                        value={currencyCount}
                        icon={<Coins className="size-4" aria-hidden="true" />}
                    />
                </StatGrid>

                <Panel title={title} description={t((d) => d.pages.walletBanks.panelHint)} flush>
                    {banks.length === 0 ? (
                        <EmptyState
                            icon={<Landmark className="size-5" />}
                            title={t((d) => d.pages.walletBanks.empty)}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.walletBanks.bank)}</th>
                                    <th className="col-figure">{t((d) => d.pages.walletBanks.colTxs)}</th>
                                    <th className="col-figure">{t((d) => d.pages.walletBanks.accounts)}</th>
                                    <th>{t((d) => d.pages.walletBanks.firstSeen)}</th>
                                    <th>{t((d) => d.pages.walletBanks.lastSeen)}</th>
                                    <th>{t((d) => d.pages.walletBanks.activity)}</th>
                                </>
                            }
                        >
                            {banks.map((bank) => {
                                const key = bank.bankId ?? 'none';
                                const named = bank.name !== null;

                                return (
                                    <tr key={key}>
                                        <td className="col-primary">
                                            <div className="identity">
                                                {named ? (
                                                    <BankMark
                                                        bank={{
                                                            name: bank.name ?? '',
                                                            code: bank.code,
                                                            active: bank.active,
                                                            logoUrl: bank.logoUrl,
                                                        }}
                                                    />
                                                ) : (
                                                    <span
                                                        className="bank-tile"
                                                        // A neutral disc for the bucket that is not a
                                                        // bank: the mark reads as a tile without
                                                        // claiming a bank's own colour.
                                                        style={{ '--bank-hue': 220 } as CSSProperties}
                                                        aria-hidden="true"
                                                    >
                                                        <Landmark className="size-4" />
                                                    </span>
                                                )}
                                                <div className="min-w-0">
                                                    <div className="identity-name truncate">
                                                        {bank.name ?? t((d) => d.pages.walletBanks.noBank)}
                                                    </div>
                                                    <div className="identity-sub truncate">
                                                        {named
                                                            ? (bank.code ?? '')
                                                            : t((d) => d.pages.walletBanks.noBankHint)}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="col-figure font-semibold">
                                            <CellLabel icon={<Hash className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.walletBanks.colTxs)}
                                            </CellLabel>
                                            <span title={shareNote(bank.transactionCount)}>
                                                {bank.transactionCount}
                                            </span>
                                        </td>
                                        <td className="col-figure">
                                            <CellLabel icon={<WalletCards className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.walletBanks.accounts)}
                                            </CellLabel>
                                            {bank.accountCount}
                                        </td>
                                        <td>
                                            <CellLabel icon={<CalendarRange className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.walletBanks.firstSeen)}
                                            </CellLabel>
                                            {bank.firstBookedAt === null ? '—' : formatDate(bank.firstBookedAt, locale)}
                                        </td>
                                        <td>
                                            <CellLabel icon={<CalendarRange className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.walletBanks.lastSeen)}
                                            </CellLabel>
                                            {bank.lastBookedAt === null ? '—' : formatDate(bank.lastBookedAt, locale)}
                                        </td>
                                        <td>
                                            <CellLabel
                                                icon={<ArrowLeftRight className="size-3.5" aria-hidden="true" />}
                                            >
                                                {t((d) => d.pages.walletBanks.activity)}
                                            </CellLabel>
                                            <div className="flex flex-col gap-0.5">
                                                {bank.flows.map((flow) => (
                                                    <div
                                                        key={flow.currencyCode}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <span className="mono text-muted-foreground">
                                                            {flow.currencyCode}
                                                        </span>
                                                        <span className="is-in inline-flex items-center gap-1">
                                                            <ArrowDownLeft className="size-3.5" aria-hidden="true" />
                                                            {figure(flow.inMinor, flow.currencyCode)}
                                                        </span>
                                                        <span className="is-out inline-flex items-center gap-1">
                                                            <ArrowUpRight className="size-3.5" aria-hidden="true" />
                                                            {figure(flow.outMinor, flow.currencyCode)}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </DataTable>
                    )}
                </Panel>
            </>
        );
    })();

    return (
        <>
            <Head title={title} />
            <Page title={title} lede={t((d) => d.pages.walletBanks.description)}>
                {body}
            </Page>
        </>
    );
}
