import { Head } from '@inertiajs/react';
import { ChartColumn, Landmark, Tags } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';

import { apiRequest } from '../../lib/http';
import AppShell from '../../components/AppShell';
import { DataTable, Page, Panel, Toolbar } from '../../components/panel';
import { PanelDateRange } from '../../components/PanelDatePicker';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { getActiveSubjectId } from '../../lib/subjectStore';
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

interface Reports {
    byTag: TagRow[];
    byBank: BankRow[];
    byCategory: CategoryRow[];
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
        from: str(body, 'from'),
        to: str(body, 'to'),
    };
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
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;
        const params = new URLSearchParams();

        if (from !== '') {
            params.set('from', from);
        }

        if (to !== '') {
            params.set('to', to);
        }

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

    /** The widest total in a set, so the bars share one scale rather than each filling its row. */
    const widest = (values: number[]): number => Math.max(1, ...values.map((value) => Math.abs(value)));

    const tagScale = widest(reports?.byTag.map((row) => row.totalMinor) ?? []);
    const bankScale = widest(reports?.byBank.map((row) => row.inMinor + row.outMinor) ?? []);

    return (
        <AppShell>
            <Head title={t((d) => d.pages.walletReports.title)} />
            <Page title={t((d) => d.pages.walletReports.title)} lede={t((d) => d.pages.walletReports.description)}>
                <Toolbar>
                    <PanelDateRange
                        value={{ from: from === '' ? null : from, to: to === '' ? null : to }}
                        onChange={(next) => {
                            setFrom(next.from ?? '');
                            setTo(next.to ?? '');
                        }}
                    />
                </Toolbar>

                {failed ? <p className="note">{t((d) => d.pages.walletReports.loadFailed)}</p> : null}

                {reports === null && !failed ? (
                    <div className="space-y-2">
                        {[0, 1, 2].map((index) => (
                            <Skeleton key={index} className="tile h-24 w-full" />
                        ))}
                    </div>
                ) : null}

                {reports === null ? null : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel
                            title={t((d) => d.pages.walletReports.byTag)}
                            description={t((d) => d.pages.walletReports.byTagHint)}
                            icon={<Tags className="size-4" />}
                            flush
                        >
                            {reports.byTag.length === 0 ? (
                                <p className="note p-4">{t((d) => d.pages.walletReports.empty)}</p>
                            ) : (
                                <ul className="divide-border divide-y px-4 py-1">
                                    {reports.byTag.map((row) => (
                                        <li key={`${row.tagId}-${row.currencyCode}`} className="py-2.5">
                                            <div className="flex items-baseline justify-between gap-3">
                                                <span className="text-xs font-semibold">
                                                    {row.name === ''
                                                        ? t((d) => d.pages.walletReports.untagged)
                                                        : row.name}
                                                </span>
                                                <span className="text-sm font-semibold tabular-nums">
                                                    {formatMinor(row.totalMinor, row.currencyCode, locale)}
                                                </span>
                                            </div>
                                            <div className="bg-muted mt-1.5 h-1.5 overflow-hidden rounded-full">
                                                <div
                                                    className="h-full rounded-full bg-[var(--brand-600)]"
                                                    style={{
                                                        width: `${String((Math.abs(row.totalMinor) / tagScale) * 100)}%`,
                                                    }}
                                                />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>

                        <Panel
                            title={t((d) => d.pages.walletReports.byBank)}
                            description={t((d) => d.pages.walletReports.byBankHint)}
                            icon={<Landmark className="size-4" />}
                            flush
                        >
                            {reports.byBank.length === 0 ? (
                                <p className="note p-4">{t((d) => d.pages.walletReports.empty)}</p>
                            ) : (
                                <ul className="divide-border divide-y px-4 py-1">
                                    {reports.byBank.map((row) => (
                                        <li key={`${row.bankId ?? 'none'}-${row.currencyCode}`} className="py-2.5">
                                            <div className="flex items-baseline justify-between gap-3">
                                                <span className="text-xs font-semibold">
                                                    {row.name ?? t((d) => d.pages.walletReports.noBank)}
                                                </span>
                                                <span className="note">{row.currencyCode}</span>
                                            </div>
                                            <div className="mt-1 flex flex-wrap justify-between gap-2 text-xs tabular-nums">
                                                <span className="text-success">
                                                    {t((d) => d.pages.walletReports.moneyIn)}{' '}
                                                    {formatMinor(row.inMinor, row.currencyCode, locale)}
                                                </span>
                                                <span className="text-destructive">
                                                    {t((d) => d.pages.walletReports.moneyOut)}{' '}
                                                    {formatMinor(row.outMinor, row.currencyCode, locale)}
                                                </span>
                                            </div>
                                            <div className="bg-muted mt-1.5 flex h-1.5 overflow-hidden rounded-full">
                                                <div
                                                    className="bg-success h-full"
                                                    style={{
                                                        width: `${String((row.inMinor / bankScale) * 100)}%`,
                                                    }}
                                                />
                                                <div
                                                    className="bg-destructive h-full"
                                                    style={{
                                                        width: `${String((row.outMinor / bankScale) * 100)}%`,
                                                    }}
                                                />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>

                        <Panel
                            title={t((d) => d.pages.walletReports.byCategory)}
                            description={t((d) => d.pages.walletReports.byCategoryHint)}
                            icon={<ChartColumn className="size-4" />}
                            flush
                            className="lg:col-span-2"
                        >
                            {reports.byCategory.length === 0 ? (
                                <p className="note p-4">{t((d) => d.pages.walletReports.empty)}</p>
                            ) : (
                                <DataTable
                                    stacked
                                    head={
                                        <>
                                            <th>{t((d) => d.pages.walletTransactions.classify.category)}</th>
                                            <th>{t((d) => d.pages.walletTransactions.form.currency)}</th>
                                            <th>{t((d) => d.pages.walletReports.total)}</th>
                                        </>
                                    }
                                >
                                    {reports.byCategory.map((row) => (
                                        <tr key={`${row.categoryCode}-${row.currencyCode}`}>
                                            <td className="col-primary">
                                                {t(
                                                    (d) =>
                                                        d.pages.walletTransactions.categories[row.categoryCode] ??
                                                        row.categoryCode,
                                                )}
                                            </td>
                                            <td className="col-hide">{row.currencyCode}</td>
                                            <td
                                                className={`tabular-nums whitespace-nowrap${
                                                    row.totalMinor < 0 ? ' text-destructive' : ''
                                                }`}
                                            >
                                                {formatMinor(row.totalMinor, row.currencyCode, locale)}
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            )}
                        </Panel>
                    </div>
                )}
            </Page>
        </AppShell>
    );
}
