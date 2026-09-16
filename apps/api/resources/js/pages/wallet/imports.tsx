import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, PlusIcon } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { listStatementImports, undoStatementImport } from '../../api/statementImportApi';
import AppShell from '../../components/AppShell';
import ConfirmDialog from '../../components/ConfirmDialog';
import { DataTable, EmptyState, Page, Panel, PanelButton, Pill } from '../../components/panel';
import Pagination from '../../components/Pagination';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDateTime } from '../../lib/format';
import { getActiveSubjectId } from '../../lib/subjectStore';
import type { StatementImport } from '../../types/statementImport';

/** Mirrors `routes/web.php`, so each path appears once on this screen. */
const NEW_IMPORT_PATH = '/wallet/imports/new';

const TRANSACTIONS_PATH = '/wallet/transactions';

/**
 * Every statement imported for this taxpayer.
 *
 * The page exists for one thing above all: undoing. "I imported the wrong month" and "I picked the
 * wrong account" are the two mistakes this feature invites, and both are otherwise repaired by
 * deleting three hundred transactions by hand.
 *
 * It is also where an import that is still being worked on is picked up again — reading a statement
 * is several steps with a person in the middle of them, and a person who closed the tab has to be
 * able to come back to it.
 */
export default function WalletImports(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [imports, setImports] = useState<StatementImport[] | null>(null);
    const [total, setTotal] = useState(0);
    const [perPage, setPerPage] = useState(25);
    const [page, setPage] = useState(1);
    const [failed, setFailed] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    const [undoing, setUndoing] = useState<StatementImport | null>(null);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listStatementImports(subjectId, page).then(
            (loaded) => {
                if (active) {
                    setImports(loaded.imports);
                    setTotal(loaded.total);
                    setPerPage(loaded.perPage);
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
    }, [subjectId, page, reloadKey]);

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.walletImports.title)}>
                    <Panel title={t((d) => d.pages.walletImports.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    /** A statement still being worked on opens where it was left; a finished one shows what it did. */
    const open = (statement: StatementImport): void => {
        router.visit(`${NEW_IMPORT_PATH}?import=${encodeURIComponent(statement.id)}`);
    };

    return (
        <AppShell>
            <Head title={t((d) => d.pages.walletImports.title)} />
            <Page title={t((d) => d.pages.walletImports.title)} lede={t((d) => d.pages.walletImports.description)}>
                <Panel
                    title={t((d) => d.pages.walletImports.historyTitle)}
                    description={t((d) => d.pages.walletImports.historyHint)}
                    icon={<FileSpreadsheet className="size-4" />}
                    flush
                    aside={
                        <PanelButton
                            primary
                            inline
                            onClick={() => {
                                router.visit(NEW_IMPORT_PATH);
                            }}
                        >
                            <PlusIcon className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.walletImports.start)}
                        </PanelButton>
                    }
                    foot={
                        <Pagination
                            page={page}
                            pageSize={perPage}
                            total={total}
                            unit={t((d) => d.pages.walletImports.historyTitle)}
                            onPageChange={setPage}
                        />
                    }
                >
                    {imports === null ? (
                        failed ? (
                            <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                        ) : (
                            <div className="space-y-2 p-3">
                                {[0, 1, 2].map((index) => (
                                    <Skeleton key={index} className="tile h-9 w-full" />
                                ))}
                            </div>
                        )
                    ) : imports.length === 0 ? (
                        <EmptyState
                            icon={<FileSpreadsheet className="size-5" />}
                            title={t((d) => d.pages.walletImports.empty)}
                            description={t((d) => d.pages.walletImports.description)}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.walletTransactions.columns.date)}</th>
                                    <th>{t((d) => d.pages.walletImports.columnsHeader)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {imports.map((statement) => (
                                <tr key={statement.id}>
                                    <td className="col-primary whitespace-nowrap tabular-nums">
                                        {formatDateTime(statement.createdAt, locale)}
                                    </td>
                                    <td>
                                        <div>{statement.format ?? '—'}</div>
                                        <div className="note">
                                            {t((d) => d.pages.walletImports.done.summary)
                                                .replace('{imported}', String(statement.importedCount))
                                                .replace('{duplicates}', String(statement.duplicateCount))}
                                        </div>
                                    </td>
                                    <td>
                                        <Pill tone={statement.status === 'IMPORTED' ? 'accent' : 'neutral'}>
                                            {t((d) => d.pages.walletImports.status)[statement.status] ??
                                                statement.status}
                                        </Pill>
                                    </td>
                                    <td className="col-end text-right">
                                        <div className="flex justify-end gap-1.5">
                                            <PanelButton
                                                onClick={() => {
                                                    if (statement.status === 'IMPORTED') {
                                                        router.visit(TRANSACTIONS_PATH);

                                                        return;
                                                    }

                                                    open(statement);
                                                }}
                                            >
                                                {statement.status === 'IMPORTED'
                                                    ? t((d) => d.pages.walletImports.done.viewTransactions)
                                                    : t((d) => d.common.open)}
                                            </PanelButton>
                                            {statement.status === 'IMPORTED' ? (
                                                <PanelButton
                                                    variant="ghost"
                                                    onClick={() => {
                                                        setUndoing(statement);
                                                    }}
                                                >
                                                    {t((d) => d.pages.walletImports.done.undo)}
                                                </PanelButton>
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {undoing === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setUndoing(null);
                        }
                    }}
                    title={t((d) => d.pages.walletImports.done.undoTitle)}
                    description={t((d) => d.pages.walletImports.done.undoHint)}
                    detail={
                        <p className="note">
                            {t((d) => d.pages.walletImports.done.summary)
                                .replace('{imported}', String(undoing.importedCount))
                                .replace('{duplicates}', String(undoing.duplicateCount))}
                        </p>
                    }
                    confirmLabel={t((d) => d.pages.walletImports.done.undo)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        const statement = undoing;

                        try {
                            await undoStatementImport(subjectId, statement.id);
                            toast.success(t((d) => d.pages.walletImports.done.undone));
                            setUndoing(null);
                            setReloadKey((current) => current + 1);
                        } catch (cause: unknown) {
                            toast.error(
                                t((d) => d.common.deleteFailed),
                                {
                                    description: describeApiFailure(cause, t),
                                },
                            );

                            throw cause;
                        }
                    }}
                />
            )}
        </AppShell>
    );
}
