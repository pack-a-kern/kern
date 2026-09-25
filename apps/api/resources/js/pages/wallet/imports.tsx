import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    FileSpreadsheet,
    ListChecks,
    Loader,
    PlusIcon,
    Table2,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteStatementImport, listStatementImports } from '../../api/statementImportApi';
import AppShell from '../../components/AppShell';
import BulkActions from '../../components/BulkActions';
import Checkbox from '../../components/Checkbox';
import ConfirmDialog from '../../components/ConfirmDialog';
import { ActionGroup, DataTable, EmptyState, Page, Panel, PanelButton } from '../../components/panel';
import { DropdownMenuItem } from '../../components/ui/dropdown-menu';
import { useRowSelection } from '../../hooks/useRowSelection';
import Pagination from '../../components/Pagination';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDateTime } from '../../lib/format';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { cn } from '../../lib/utils';
import type { StatementImport } from '../../types/statementImport';

/**
 * What deleting these statements takes with it, said before it happens rather than after.
 *
 * In red and counted, because one of the three lines removes real money from the ledger: a person
 * who reads "5 statements" and nothing else has not been told that four hundred transactions go too.
 */
function Loses({ statements }: { statements: StatementImport[] }): JSX.Element {
    const { t } = useTranslation();
    const transactions = statements.reduce((total, one) => total + one.importedCount, 0);

    return (
        <ul className="text-destructive space-y-0.5 text-xs">
            <li>{t((d) => d.pages.walletImports.losesImports).replace('{count}', String(statements.length))}</li>
            {transactions === 0 ? null : (
                <li className="font-semibold">
                    {t((d) => d.pages.walletImports.losesTransactions).replace('{count}', String(transactions))}
                </li>
            )}
            <li>{t((d) => d.pages.walletImports.losesFiles).replace('{count}', String(statements.length))}</li>
        </ul>
    );
}

/** Where a statement has got to: one word, its own colour and its own mark. */
function Stage({ status }: { status: StatementImport['status'] }): JSX.Element {
    const { t } = useTranslation();
    const [tone, Icon]: ['ok' | 'warn' | 'bad' | 'idle', LucideIcon] = {
        READING: ['idle', Loader],
        MAPPING: ['warn', Table2],
        REVIEW: ['warn', ListChecks],
        IMPORTING: ['idle', Loader],
        IMPORTED: ['ok', CheckCircle2],
        FAILED: ['bad', AlertTriangle],
    }[status] as ['ok' | 'warn' | 'bad' | 'idle', LucideIcon];

    return (
        <span className={cn('inline-flex items-center gap-1.5 text-xs font-medium', TONE_TEXT[tone])}>
            <Icon
                className={cn(
                    'size-3.5 shrink-0',
                    status === 'READING' || status === 'IMPORTING' ? 'animate-spin' : '',
                )}
            />
            {t((d) => d.pages.walletImports.status)[status] ?? status}
        </span>
    );
}

/** The colour of each stage, from the palette `Status` already uses. */
const TONE_TEXT = {
    ok: 'text-emerald-700 dark:text-emerald-400',
    warn: 'text-amber-700 dark:text-amber-400',
    bad: 'text-destructive',
    idle: 'text-muted-foreground',
};

/** Mirrors `routes/web.php`, so each path appears once on this screen. */
const NEW_IMPORT_PATH = '/wallet/imports/new';

/**
 * Every statement imported for this taxpayer.
 *
 * Where a reading is picked up again — it is several steps with a person in the middle of them, and
 * one who closed the tab has to be able to come back — and where a mistake is undone. "I imported
 * the wrong month" and "I picked the wrong account" are the two this feature invites, and both are
 * repaired by deleting the import: the transactions it wrote, its rows and its file go together.
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
    const [deleting, setDeleting] = useState<StatementImport | null>(null);
    const [deletingSelected, setDeletingSelected] = useState(false);
    const [bulkDeleting, setBulkDeleting] = useState(false);

    /*
     * Only what can actually be deleted is tickable — everything but an import being written.
     *
     * `IMPORTING` is a job putting rows into the ledger right now, and a checkbox on it would offer
     * a bulk action that refuses it.
     */
    const deletable = useMemo(
        () => (imports ?? []).filter((statement) => statement.status !== 'IMPORTING').map((statement) => statement.id),
        [imports],
    );
    const selection = useRowSelection(deletable);

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
                        <div className="flex items-center gap-1.5">
                            {/* Appears only once something is ticked, beside the way to start a new one. */}
                            <BulkActions count={selection.size} disabled={bulkDeleting}>
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => {
                                        setDeletingSelected(true);
                                    }}
                                >
                                    <Trash2 className="size-4" />
                                    {t((d) => d.pages.walletImports.deleteSelected)}
                                </DropdownMenuItem>
                            </BulkActions>
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
                        </div>
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
                                    <th className="w-8">
                                        <Checkbox
                                            checked={selection.allOnPage}
                                            indeterminate={selection.someOnPage}
                                            onCheckedChange={selection.toggleAll}
                                            aria-label={t((d) => d.common.selectPage)}
                                        />
                                    </th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.date)}</th>
                                    <th>{t((d) => d.pages.walletImports.table.statement)}</th>
                                    <th>{t((d) => d.pages.walletImports.table.kind)}</th>
                                    <th>{t((d) => d.pages.walletImports.table.state)}</th>
                                    <th />
                                </>
                            }
                        >
                            {imports.map((statement) => (
                                <tr key={statement.id} className={cn(selection.has(statement.id) && 'row-selected')}>
                                    <td>
                                        {deletable.includes(statement.id) ? (
                                            <Checkbox
                                                checked={selection.has(statement.id)}
                                                onCheckedChange={(next) => {
                                                    selection.toggle(statement.id, next);
                                                }}
                                                aria-label={t((d) => d.common.actions)}
                                            />
                                        ) : null}
                                    </td>
                                    <td className="col-primary whitespace-nowrap tabular-nums">
                                        {formatDateTime(statement.createdAt, locale)}
                                    </td>
                                    <td>{statement.bankName ?? t((d) => d.pages.walletImports.table.noBank)}</td>
                                    <td className="whitespace-nowrap">{statement.format ?? '—'}</td>
                                    <td>
                                        <Stage status={statement.status} />
                                        {statement.status === 'IMPORTED' ? (
                                            <div className="note">
                                                {t((d) => d.pages.walletImports.done.summary)
                                                    .replace('{imported}', String(statement.importedCount))
                                                    .replace('{duplicates}', String(statement.duplicateCount))}
                                            </div>
                                        ) : null}
                                    </td>
                                    <td className="col-end text-right">
                                        <ActionGroup
                                            actions={[
                                                // Only while there is something to come back to. An
                                                // import that is finished opens on a screen with
                                                // nothing left to do on it, and "view" that lands on
                                                // the transactions list is a link pretending to be
                                                // about this row.
                                                ...(statement.status === 'IMPORTED'
                                                    ? []
                                                    : [
                                                          {
                                                              key: 'open',
                                                              icon: (
                                                                  <ArrowRight className="size-3.5" aria-hidden="true" />
                                                              ),
                                                              label: t((d) => d.common.open),
                                                              onClick: () => {
                                                                  open(statement);
                                                              },
                                                          },
                                                      ]),
                                                // A write in flight is the one moment deleting would
                                                // corrupt something rather than merely remove it.
                                                ...(statement.status === 'IMPORTING'
                                                    ? []
                                                    : [
                                                          {
                                                              key: 'delete',
                                                              icon: <Trash2 className="size-3.5" aria-hidden="true" />,
                                                              label: t((d) => d.pages.walletImports.delete),
                                                              tone: 'destructive' as const,
                                                              onClick: () => {
                                                                  setDeleting(statement);
                                                              },
                                                          },
                                                      ]),
                                            ]}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {!deletingSelected ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setDeletingSelected(false);
                        }
                    }}
                    title={t((d) => d.pages.walletImports.deleteSelectedTitle).replace(
                        '{count}',
                        String(selection.size),
                    )}
                    description={t((d) => d.pages.walletImports.deleteHint)}
                    detail={<Loses statements={imports?.filter((one) => selection.has(one.id)) ?? []} />}
                    confirmLabel={t((d) => d.pages.walletImports.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        setBulkDeleting(true);
                        let gone = 0;

                        try {
                            // One at a time: each is its own document and its own rows, and one that
                            // refuses says so without taking the others with it.
                            for (const id of selection.ids) {
                                await deleteStatementImport(subjectId, id);
                                gone += 1;
                            }

                            toast.success(
                                t((d) => d.pages.walletImports.deletedCount).replace('{count}', String(gone)),
                            );
                            selection.clear();
                            setDeletingSelected(false);
                        } catch (cause: unknown) {
                            toast.error(
                                t((d) => d.common.deleteFailed),
                                {
                                    description: describeApiFailure(cause, t),
                                },
                            );
                            throw cause;
                        } finally {
                            setBulkDeleting(false);
                            setReloadKey((current) => current + 1);
                        }
                    }}
                />
            )}

            {deleting === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setDeleting(null);
                        }
                    }}
                    title={t((d) => d.pages.walletImports.deleteTitle)}
                    description={t((d) => d.pages.walletImports.deleteHint)}
                    detail={<Loses statements={[deleting]} />}
                    confirmLabel={t((d) => d.pages.walletImports.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        const statement = deleting;

                        try {
                            await deleteStatementImport(subjectId, statement.id);
                            toast.success(t((d) => d.pages.walletImports.deleted));
                            setDeleting(null);
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
