import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, Upload } from 'lucide-react';
import { type JSX, useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { listAccounts, listBanks, listTags } from '../../api/walletApi';
import {
    commitStatementImport,
    getStatementImport,
    previewStatementForm,
    setStatementImportMapping,
    startStatementImport,
    updateStatementImportRows,
} from '../../api/statementImportApi';
import AppShell from '../../components/AppShell';
import Checkbox from '../../components/Checkbox';
import Pagination from '../../components/Pagination';
import TagsInput from '../../components/wallet/TagsInput';
import {
    DataTable,
    Detail,
    DetailList,
    Field,
    FieldGrid,
    Page,
    Panel,
    PanelButton,
    PanelInput,
    PanelSelect,
    Pill,
    Toolbar,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useRowSelection } from '../../hooks/useRowSelection';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { getActiveSubjectId } from '../../lib/subjectStore';
import {
    bankLabel,
    categoriesFor,
    CURRENCIES,
    formatMinor,
    isStreamKind,
    sourceName,
    type AccountSummary,
    type Bank,
    type Tag,
} from '../../types/wallet';
import { useImportsChannel } from '../../hooks/useImportsChannel';
import type {
    StatementClassification,
    StatementField,
    StatementForm,
    StatementFormPreview,
    StatementImportDetail,
    StatementImportEvent,
} from '../../types/statementImport';

const IMPORTS_PATH = '/wallet/imports';

const TRANSACTIONS_PATH = '/wallet/transactions';

/** How often the screen asks again while the file is being read or the rows written. */
// Long enough that a select does not fire a request per keystroke, short enough that the rebuilt
// preview feels like it is answering you.
const PREVIEW_DEBOUNCE_MS = 400;

/** The date shapes the reader recognises, offered in the order a Romanian export uses them. */
// The shapes the reader describes and the parser can read. `D MONTH YYYY` is the one a Romanian
// bank writes in words — "12 septembrie 2026" — and the month names live in the server's config.
const DATE_FORMATS = [
    'DD.MM.YYYY',
    'DD/MM/YYYY',
    'DD-MM-YYYY',
    'YYYY-MM-DD',
    'YYYY/MM/DD',
    'DD.MM.YY',
    'MM/DD/YYYY',
    'D MONTH YYYY',
];

/**
 * Reading a bank statement into transactions.
 *
 * ## One page, four stages
 *
 * Upload, match the columns, go through the rows, import. They are stages of one thing rather than
 * four screens, and which one is shown comes from the import's own status — so a person who closes
 * the tab and comes back through the history lands exactly where they left off, and a file still
 * being scanned shows that rather than an empty form.
 *
 * ## Nothing is imported until the last press
 *
 * Confirming the columns builds rows to look at; it writes no transaction. That separation is the
 * point of the whole screen: a mapping that read an amount column as a balance is obvious in a
 * preview and invisible in a ledger.
 */
export default function WalletImport(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [importId, setImportId] = useState<string | null>(() => {
        if (typeof window === 'undefined') {
            return null;
        }

        return new URLSearchParams(window.location.search).get('import');
    });
    const [detail, setDetail] = useState<StatementImportDetail | null>(null);
    const [accounts, setAccounts] = useState<AccountSummary[]>([]);
    const [banks, setBanks] = useState<Bank[]>([]);
    const [tags, setTags] = useState<Tag[]>([]);

    // Step 1 — the file.
    const [accountId, setAccountId] = useState('');
    const [bankId, setBankId] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [starting, setStarting] = useState(false);

    // Step 2 — the columns.
    const [mapping, setMapping] = useState<StatementForm | null>(null);
    const [applying, setApplying] = useState(false);
    const [mappingError, setMappingError] = useState<string | null>(null);
    /*
     * Reopening the form once the rows exist.
     *
     * The server allows it — an import stays open through review — and it is the only way to see
     * what was matched to what, or to fix one field without starting the upload again.
     */
    const [remapping, setRemapping] = useState(false);
    const [formPreview, setFormPreview] = useState<StatementFormPreview | null>(null);

    // Step 3 — the rows.
    const [filter, setFilter] = useState('');
    const [page, setPage] = useState(1);
    const [bulkCategory, setBulkCategory] = useState('');
    const [bulkTags, setBulkTags] = useState<string[]>([]);
    const [working, setWorking] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listAccounts(subjectId).then(
            (loaded) => {
                if (active) {
                    // A statement lands in a source that takes transactions: a cash holding is one
                    // declared value and a deposit's entries come from its terms.
                    const usable = loaded.accounts.filter((account) => isStreamKind(account.kind));
                    setAccounts(usable);
                    setAccountId((current) => (current === '' ? (usable[0]?.id ?? '') : current));
                }
            },
            () => {
                if (active) {
                    setAccounts([]);
                }
            },
        );

        listBanks(subjectId).then(
            (loaded) => {
                if (active) {
                    setBanks(loaded);
                }
            },
            () => {
                if (active) {
                    setBanks([]);
                }
            },
        );

        listTags(subjectId).then(
            (loaded) => {
                if (active) {
                    setTags(loaded);
                }
            },
            () => {
                if (active) {
                    setTags([]);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId]);

    // The import itself, and while it is being read or written, again in a moment.
    useEffect(() => {
        if (subjectId === null || importId === null) {
            return;
        }

        let active = true;

        const load = (): void => {
            getStatementImport(subjectId, importId, {
                ...(filter === '' ? {} : { filter }),
                page,
            }).then(
                (loaded) => {
                    if (!active) {
                        return;
                    }

                    setDetail(loaded);
                    /*
                     * No form until there is a file to describe.
                     *
                     * While the file is still being read there are no columns, so a guess made then is
                     * a form of nothing — and because a form already on screen is kept (a person may be
                     * halfway through changing it), that empty guess used to outlive the AI's answer
                     * arriving a moment later. The selects pointed at columns that did not exist, and
                     * the preview had nothing to show, until a refresh started over.
                     */
                    setMapping((current) =>
                        loaded.import.status === 'READING' ? null : (current ?? loaded.mapping ?? suggestForm(loaded)),
                    );
                },
                () => {
                    if (active) {
                        setDetail(null);
                    }
                },
            );
        };

        load();

        return () => {
            active = false;
        };
    }, [subjectId, importId, filter, page, reloadKey]);

    /*
     * Told, rather than asked every two seconds.
     *
     * The timer this replaces only ran while the status was `READING` or `IMPORTING`, which left
     * `REVIEW` unwatched — and `REVIEW` is exactly when the counterparty proposals arrive, so a
     * person's categories and tags reached the database and never the screen.
     */
    const onImportChanged = useCallback(
        (event: StatementImportEvent): void => {
            if (event.importId === importId) {
                setReloadKey((current) => current + 1);
            }
        },
        [importId],
    );

    useImportsChannel(subjectId, onImportChanged);

    /*
     * Whether the form is on screen: while it is, the preview follows what is chosen in it.
     *
     * Read from the loaded import rather than from `status`, which is derived further down — a
     * `const` read above its own declaration is a crash, not a warning.
     */
    const showsForm = detail?.import.status === 'MAPPING' || (detail?.import.status === 'REVIEW' && remapping);

    useEffect(() => {
        if (subjectId === null || importId === null || mapping === null || !showsForm) {
            return;
        }

        let active = true;

        const handle = window.setTimeout(() => {
            previewStatementForm(subjectId, importId, mapping).then(
                (loaded) => {
                    if (active) {
                        setFormPreview(loaded);
                    }
                },
                () => {
                    // A half-filled form is the normal state of a form being filled in: no preview
                    // rather than an error, which is what the empty state already says.
                    if (active) {
                        setFormPreview(null);
                    }
                },
            );
        }, PREVIEW_DEBOUNCE_MS);

        return () => {
            active = false;
            window.clearTimeout(handle);
        };
    }, [subjectId, importId, mapping, showsForm]);

    const rows = useMemo(() => detail?.rows ?? [], [detail]);
    const selection = useRowSelection(useMemo(() => rows.map((row) => row.id), [rows]));

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

    const status = detail?.import.status ?? null;

    const upload = async (): Promise<void> => {
        if (file === null || accountId === '') {
            return;
        }

        setStarting(true);

        try {
            const started = await startStatementImport(subjectId, file, accountId, bankId === '' ? null : bankId);
            setImportId(started.id);
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.walletImports.failed),
                { description: describeApiFailure(cause, t) },
            );
        } finally {
            setStarting(false);
        }
    };

    const applyMapping = async (): Promise<void> => {
        if (importId === null || mapping === null) {
            return;
        }

        setApplying(true);
        setMappingError(null);

        try {
            await setStatementImportMapping(subjectId, importId, mapping);
            setPage(1);
            setRemapping(false);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            setMappingError(describeApiFailure(cause, t));
        } finally {
            setApplying(false);
        }
    };

    const applyToSelection = async (changes: Parameters<typeof updateStatementImportRows>[3]): Promise<void> => {
        if (importId === null || selection.size === 0) {
            return;
        }

        setWorking(true);

        try {
            await updateStatementImportRows(subjectId, importId, [...selection.ids], changes);
            selection.clear();
            setBulkCategory('');
            setBulkTags([]);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.walletImports.failed),
                { description: describeApiFailure(cause, t) },
            );
        } finally {
            setWorking(false);
        }
    };

    const commit = async (): Promise<void> => {
        if (importId === null) {
            return;
        }

        setWorking(true);

        try {
            await commitStatementImport(subjectId, importId);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.walletImports.failed),
                { description: describeApiFailure(cause, t) },
            );
        } finally {
            setWorking(false);
        }
    };

    return (
        <AppShell>
            <Head title={t((d) => d.pages.walletImports.title)} />
            <Page title={t((d) => d.pages.walletImports.title)} lede={t((d) => d.pages.walletImports.description)}>
                {status === null ? (
                    <Panel
                        title={t((d) => d.pages.walletImports.steps.file)}
                        description={t((d) => d.pages.walletImports.file.hint)}
                        icon={<Upload className="size-4" />}
                        aside={<Steps status={status} />}
                        footActions={
                            <PanelButton
                                primary
                                disabled={starting || file === null || accountId === ''}
                                onClick={() => {
                                    void upload();
                                }}
                            >
                                {starting
                                    ? t((d) => d.pages.walletImports.file.uploading)
                                    : t((d) => d.pages.walletImports.start)}
                            </PanelButton>
                        }
                    >
                        <FieldGrid>
                            <Field label={t((d) => d.pages.walletImports.file.account)} htmlFor="import-account">
                                <PanelSelect
                                    id="import-account"
                                    value={accountId}
                                    onValueChange={setAccountId}
                                    options={accounts.map((account) => ({
                                        value: account.id,
                                        label: sourceName(
                                            account,
                                            t((d) => d.pages.wallet.generalSource),
                                        ),
                                    }))}
                                />
                            </Field>

                            <Field label={t((d) => d.pages.walletImports.file.bank)} htmlFor="import-bank">
                                <PanelSelect
                                    searchable
                                    id="import-bank"
                                    value={bankId}
                                    placeholder={t((d) => d.pages.walletTransactions.bank.none)}
                                    emptyLabel={t((d) => d.pages.walletTransactions.bank.none)}
                                    onValueChange={setBankId}
                                    options={banks.map((bank) => ({ value: bank.id, label: bankLabel(bank) }))}
                                />
                            </Field>

                            <Field label={t((d) => d.pages.walletImports.file.choose)} htmlFor="import-file">
                                <PanelInput
                                    id="import-file"
                                    type="file"
                                    accept=".csv,.xlsx,.xls,.pdf"
                                    onChange={(event) => {
                                        setFile(event.target.files?.[0] ?? null);
                                    }}
                                />
                            </Field>
                        </FieldGrid>
                    </Panel>
                ) : null}

                {status === 'READING' || status === 'IMPORTING' ? (
                    <Panel title={t((d) => d.pages.walletImports.title)} aside={<Steps status={status} />}>
                        <p className="note">
                            {status === 'READING'
                                ? t((d) => d.pages.walletImports.file.reading)
                                : t((d) => d.pages.walletImports.review.importing)}
                        </p>
                        <div className="mt-3 space-y-2">
                            {[0, 1, 2].map((index) => (
                                <Skeleton key={index} className="tile h-9 w-full" />
                            ))}
                        </div>
                    </Panel>
                ) : null}

                {status === 'FAILED' ? (
                    <Panel title={t((d) => d.pages.walletImports.failed)} aside={<Steps status={status} />}>
                        <p className="note">
                            {t((d) => d.errors.reasons)[detail?.import.failureReason ?? ''] ??
                                detail?.import.failureReason ??
                                ''}
                        </p>
                        <p className="mt-3">
                            <PanelButton
                                onClick={() => {
                                    router.visit(IMPORTS_PATH);
                                }}
                            >
                                {t((d) => d.pages.walletImports.historyTitle)}
                            </PanelButton>
                        </p>
                    </Panel>
                ) : null}

                {showsForm && mapping !== null ? (
                    <Panel
                        title={t((d) => d.pages.walletImports.columns.title)}
                        description={t((d) => d.pages.walletImports.columns.hint)}
                        icon={<FileSpreadsheet className="size-4" />}
                        aside={<Steps status={status} />}
                        footNote={
                            mappingError === null ? undefined : <span className="text-destructive">{mappingError}</span>
                        }
                        footActions={
                            <PanelButton
                                primary
                                disabled={applying}
                                onClick={() => {
                                    void applyMapping();
                                }}
                            >
                                {t((d) => d.pages.walletImports.columns.apply)}
                            </PanelButton>
                        }
                    >
                        <MappingFields detail={detail} mapping={mapping} onChange={setMapping} />

                        <RebuiltPreview preview={formPreview} />

                        {detail.preview.aiAnswer === null ? null : (
                            <details className="mt-4">
                                <summary className="note cursor-pointer">
                                    {t((d) => d.pages.walletImports.columns.aiAnswer)}
                                </summary>
                                <p className="note mt-1">{t((d) => d.pages.walletImports.columns.aiAnswerHint)}</p>
                                <pre className="border-border mt-2 overflow-x-auto rounded-md border p-3 text-xs">
                                    {JSON.stringify(detail.preview.aiAnswer, null, 2)}
                                </pre>
                            </details>
                        )}

                        <details className="mt-4">
                            <summary className="note cursor-pointer">
                                {t((d) => d.pages.walletImports.columns.raw)}
                            </summary>
                            <div className="table-wrap mt-2">
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            {detail.preview.columns.map((column) => (
                                                <th key={column.index}>
                                                    <div>
                                                        {column.header === ''
                                                            ? `#${String(column.index + 1)}`
                                                            : column.header}
                                                    </div>
                                                    <div className="note font-normal">{column.shape}</div>
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {detail.preview.rows.map((row, index) => (
                                            <tr key={index}>
                                                {detail.preview.columns.map((column) => (
                                                    <td key={column.index} className="whitespace-nowrap">
                                                        {row[column.index] ?? ''}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </Panel>
                ) : null}

                {status === 'REVIEW' && !remapping && detail !== null ? (
                    <Panel
                        title={t((d) => d.pages.walletImports.review.title)}
                        description={t((d) => d.pages.walletImports.review.hint)}
                        icon={<FileSpreadsheet className="size-4" />}
                        flush
                        aside={<Steps status={status} />}
                        foot={
                            <Pagination
                                page={detail.page}
                                pageSize={detail.perPage}
                                total={detail.total}
                                unit={t((d) => d.pages.walletTransactions.unit)}
                                onPageChange={setPage}
                            />
                        }
                    >
                        <ReviewOverview detail={detail} />

                        {detail.mapping === null ? null : <FormSummary detail={detail} form={detail.mapping} />}

                        <AiCalls classification={detail.preview.classification} />

                        <div className="border-border flex flex-wrap justify-end gap-1.5 border-b px-4 py-3">
                            <PanelButton
                                onClick={() => {
                                    setRemapping(true);
                                }}
                            >
                                {t((d) => d.pages.walletImports.columns.change)}
                            </PanelButton>
                            <PanelButton
                                primary
                                disabled={working}
                                onClick={() => {
                                    void commit();
                                }}
                            >
                                {t((d) => d.pages.walletImports.review.commit).replace(
                                    '{count}',
                                    String(detail.import.rowCount - detail.import.excludedCount),
                                )}
                            </PanelButton>
                        </div>

                        <Toolbar>
                            <PanelSelect
                                className="filter-select"
                                value={filter}
                                placeholder={t((d) => d.pages.walletImports.review.all)}
                                emptyLabel={t((d) => d.pages.walletImports.review.all)}
                                onValueChange={(next) => {
                                    setFilter(next);
                                    setPage(1);
                                }}
                                options={[
                                    { value: 'new', label: t((d) => d.pages.walletImports.review.new) },
                                    { value: 'duplicates', label: t((d) => d.pages.walletImports.review.duplicates) },
                                    { value: 'unanswered', label: t((d) => d.pages.walletImports.review.unanswered) },
                                ]}
                            />

                            {selection.size === 0 ? null : (
                                <>
                                    <span className="note">
                                        {t((d) => d.pages.walletImports.review.selected).replace(
                                            '{count}',
                                            String(selection.size),
                                        )}
                                    </span>
                                    <PanelSelect
                                        searchable
                                        className="filter-select"
                                        value={bulkCategory}
                                        placeholder={t((d) => d.pages.walletImports.review.setCategory)}
                                        emptyLabel={t((d) => d.pages.walletImports.review.setCategory)}
                                        onValueChange={(next) => {
                                            setBulkCategory(next);

                                            if (next !== '') {
                                                void applyToSelection({ category_code: next });
                                            }
                                        }}
                                        options={detail.categories.map((category) => ({
                                            value: category.code,
                                            label: t(
                                                (d) =>
                                                    d.pages.walletTransactions.categories[category.code] ??
                                                    category.code,
                                            ),
                                        }))}
                                    />
                                    <div className="min-w-48">
                                        <TagsInput
                                            value={bulkTags}
                                            suggestions={tags}
                                            onChange={(next) => {
                                                setBulkTags(next);

                                                if (next.length > 0) {
                                                    void applyToSelection({ tags: next });
                                                }
                                            }}
                                        />
                                    </div>
                                    <PanelButton
                                        disabled={working}
                                        onClick={() => {
                                            void applyToSelection({ included: false });
                                        }}
                                    >
                                        {t((d) => d.pages.walletImports.review.exclude)}
                                    </PanelButton>
                                    <PanelButton
                                        disabled={working}
                                        onClick={() => {
                                            void applyToSelection({ included: true });
                                        }}
                                    >
                                        {t((d) => d.pages.walletImports.review.include)}
                                    </PanelButton>
                                </>
                            )}
                        </Toolbar>

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
                                    <th>{t((d) => d.pages.walletTransactions.columns.description)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.amount)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.classify.category)}</th>
                                </>
                            }
                        >
                            {rows.map((row) => (
                                <tr key={row.id} className={row.included ? undefined : 'opacity-60'}>
                                    <td className="col-hide w-8">
                                        <Checkbox
                                            checked={selection.has(row.id)}
                                            onCheckedChange={(next) => {
                                                selection.toggle(row.id, next);
                                            }}
                                            aria-label={row.description}
                                        />
                                    </td>
                                    <td className="col-primary whitespace-nowrap tabular-nums">
                                        {row.bookedAt ?? '—'}
                                    </td>
                                    <td>
                                        <div>{row.description}</div>
                                        {row.counterparty === null ? null : (
                                            <div className="note">{row.counterparty}</div>
                                        )}
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {row.duplicateTransactionId === null ? null : (
                                                <Pill>{t((d) => d.pages.walletImports.review.duplicate)}</Pill>
                                            )}
                                            {row.proposalSource === 'RULE' ? (
                                                <Pill tone="accent">
                                                    {t((d) => d.pages.walletImports.review.fromRule)}
                                                </Pill>
                                            ) : null}
                                            {row.proposalSource === 'AI' ? (
                                                <Pill tone="accent">
                                                    {t((d) => d.pages.walletImports.review.fromAi)}
                                                </Pill>
                                            ) : null}
                                            {row.tags.map((tag) => (
                                                <Pill key={tag}>{tag}</Pill>
                                            ))}
                                        </div>
                                    </td>
                                    <td
                                        className={`tabular-nums whitespace-nowrap${
                                            (row.amountMinor ?? 0) < 0 ? ' text-destructive' : ''
                                        }`}
                                    >
                                        {row.amountMinor === null
                                            ? '—'
                                            : formatMinor(row.amountMinor, row.currencyCode ?? '', locale)}
                                    </td>
                                    <td>
                                        <PanelSelect
                                            searchable
                                            value={row.categoryCode ?? ''}
                                            placeholder={t((d) => d.pages.walletTransactions.classify.later)}
                                            emptyLabel={t((d) => d.pages.walletTransactions.classify.later)}
                                            onValueChange={(next) => {
                                                if (importId !== null) {
                                                    void updateStatementImportRows(subjectId, importId, [row.id], {
                                                        category_code: next === '' ? null : next,
                                                    }).then(() => {
                                                        setReloadKey((current) => current + 1);
                                                    });
                                                }
                                            }}
                                            options={categoriesFor(
                                                detail.categories,
                                                (row.amountMinor ?? 0) < 0 ? 'out' : 'in',
                                            ).map((category) => ({
                                                value: category.code,
                                                label: t(
                                                    (d) =>
                                                        d.pages.walletTransactions.categories[category.code] ??
                                                        category.code,
                                                ),
                                            }))}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    </Panel>
                ) : null}

                {status === 'IMPORTED' || status === 'UNDONE' ? (
                    <Panel title={t((d) => d.pages.walletImports.done.title)} aside={<Steps status={status} />}>
                        <p className="note">
                            {t((d) => d.pages.walletImports.done.summary)
                                .replace('{imported}', String(detail?.import.importedCount ?? 0))
                                .replace('{duplicates}', String(detail?.import.duplicateCount ?? 0))}
                        </p>
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            <PanelButton
                                primary
                                onClick={() => {
                                    router.visit(TRANSACTIONS_PATH);
                                }}
                            >
                                {t((d) => d.pages.walletImports.done.viewTransactions)}
                            </PanelButton>
                            <PanelButton
                                onClick={() => {
                                    router.visit(IMPORTS_PATH);
                                }}
                            >
                                {t((d) => d.pages.walletImports.historyTitle)}
                            </PanelButton>
                        </div>
                    </Panel>
                ) : null}
            </Page>
        </AppShell>
    );
}

/** Where the import has got to, as four words rather than a status code. */
function Steps({ status }: { status: string | null }): JSX.Element {
    const { t } = useTranslation();

    const current =
        status === null || status === 'READING'
            ? 0
            : status === 'MAPPING'
              ? 1
              : status === 'REVIEW' || status === 'IMPORTING'
                ? 2
                : 3;

    const labels = [
        t((d) => d.pages.walletImports.steps.file),
        t((d) => d.pages.walletImports.steps.columns),
        t((d) => d.pages.walletImports.steps.review),
        t((d) => d.pages.walletImports.steps.done),
    ];

    return (
        <ol className="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
            {labels.map((label, index) => (
                <li key={label} className="flex items-center gap-2">
                    <span
                        className={
                            index < current
                                ? 'bg-accent/20 text-accent flex size-5 items-center justify-center rounded-full'
                                : index === current
                                  ? 'bg-accent text-accent-foreground flex size-5 items-center justify-center rounded-full font-semibold'
                                  : 'border-border text-muted-foreground flex size-5 items-center justify-center rounded-full border'
                        }
                        aria-hidden="true"
                    >
                        {index < current ? '✓' : String(index + 1)}
                    </span>
                    <span className={index === current ? 'font-semibold' : 'text-muted-foreground'}>{label}</span>
                    {index === labels.length - 1 ? null : (
                        <span className="text-muted-foreground/40" aria-hidden="true">
                            ›
                        </span>
                    )}
                </li>
            ))}
        </ol>
    );
}

/**
 * The first transactions, as this form would read them.
 *
 * A grid of the file's own cells asks a person to do the folding in their head — which line belongs
 * to which payment, which label became the counterparty. This shows the answer instead: the same
 * rows the import would stage, built by the same code, from the sample already on the screen.
 *
 * A table with the transaction's own fields as its columns, so it reads as "this is what you will
 * get". Stacked on a phone, where five columns do not fit and one transaction at a time is the
 * useful reading anyway.
 */
function RebuiltPreview({ preview }: { preview: StatementFormPreview | null }): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <div className="mt-4">
            <div className="note mb-1">{t((d) => d.pages.walletImports.columns.rebuilt)}</div>
            <p className="note mb-2">{t((d) => d.pages.walletImports.columns.rebuiltHint)}</p>

            {preview === null || preview.transactions.length === 0 ? (
                <p className="note">{t((d) => d.pages.walletImports.columns.rebuiltEmpty)}</p>
            ) : (
                <>
                    <DataTable
                        stacked
                        head={
                            <>
                                <th>{t((d) => d.pages.walletTransactions.columns.date)}</th>
                                <th>{t((d) => d.pages.walletTransactions.columns.description)}</th>
                                <th>{t((d) => d.pages.walletImports.columns.counterparty)}</th>
                                <th>{t((d) => d.pages.walletImports.columns.reference)}</th>
                                <th className="text-right">{t((d) => d.pages.walletTransactions.columns.amount)}</th>
                            </>
                        }
                    >
                        {preview.transactions.map((row) => (
                            <tr key={row.row_number}>
                                <td className="col-primary whitespace-nowrap tabular-nums">{row.booked_at ?? '—'}</td>
                                <td>{row.text === '' ? '—' : row.text}</td>
                                <td>{row.counterparty === '' ? '—' : row.counterparty}</td>
                                <td className="tabular-nums">{row.reference === '' ? '—' : row.reference}</td>
                                <td
                                    className={
                                        'col-end whitespace-nowrap text-right tabular-nums' +
                                        ((row.amount_minor ?? 0) < 0 ? ' text-destructive' : '')
                                    }
                                >
                                    {row.amount_minor === null
                                        ? '—'
                                        : formatMinor(row.amount_minor, row.currency_code ?? '', locale)}
                                </td>
                            </tr>
                        ))}
                    </DataTable>
                    <p className="note mt-2">
                        {t((d) => d.pages.walletImports.columns.rebuiltCounts)
                            .replace('{folded}', String(preview.linesFolded))
                            .replace('{skipped}', String(preview.linesSkipped))}
                    </p>
                </>
            )}
        </div>
    );
}

/**
 * A starting point for the form, from the shapes the reader measured.
 *
 * Deliberately simple — the first date-shaped column is the date, the first number-shaped one the
 * amount, the widest text one the description — because it is only used when the model was not
 * asked or could not answer, and a person confirms it against a preview either way.
 */
function suggestForm(detail: StatementImportDetail): StatementForm {
    const columns = detail.preview.columns;
    const dateColumn = columns.find((column) => column.shape.startsWith('date'));
    const numbers = columns.filter((column) => column.shape.startsWith('number'));
    const texts = columns.filter((column) => column.shape.startsWith('text'));
    const decimal = numbers[0]?.shape.includes(',') === true ? ',' : '.';
    const split = numbers.length > 1;
    const asField = (column: { index: number } | undefined): StatementField | null =>
        column === undefined ? null : { type: 'index', value: column.index };

    const measured = dateColumn?.shape.replace('date ', '').replace(' (mostly)', '') ?? '';
    const format = DATE_FORMATS.includes(measured) ? measured : (DATE_FORMATS[0] ?? 'DD.MM.YYYY');

    return {
        // The reader only finds labels under something: a file with them has detail lines, and a
        // file without them has one transaction per line.
        layout: detail.preview.labels.length > 0 ? 'block' : 'row',
        date: { type: 'index', value: dateColumn?.index ?? 0, format },
        currency: asField(columns.find((column) => column.shape === 'currency code')),
        amounts: {
            mode: split ? 'debit_credit' : 'signed',
            amount: split ? null : asField(numbers[0]),
            debit: split ? asField(numbers[0]) : null,
            credit: split ? asField(numbers[1]) : null,
            decimal_separator: decimal,
            thousands_separator: decimal === ',' ? '.' : ',',
        },
        description: asField(texts[0]),
        // Left empty rather than guessed: which label names the other side of a payment is exactly
        // what a local guess gets wrong, and an empty select asks where a wrong one misleads.
        counterparty: { debit: null, credit: null },
        reference: { debit: null, credit: null },
        non_transaction_lines: [],
        identity_labels: [],
    };
}

/**
 * The import at a glance: what the file became, and where the answers came from.
 *
 * Numbers side by side rather than a sentence, because the point is comparing them: 12 522 lines
 * becoming 1 700 transactions is either right or badly wrong, and "966 suggested by AI" next to
 * "1 700" says at once how much is still unanswered.
 *
 * The line beneath says what the model is doing. It updates as each call finishes — the page is told
 * over the socket — so a person waiting for suggestions sees them arrive rather than refreshing.
 */
function ReviewOverview({ detail }: { detail: StatementImportDetail }): JSX.Element {
    const { t, locale } = useTranslation();
    const current = detail.import;
    const classification = detail.preview.classification;

    // Imports read before the line counts existed have none; showing zeros would claim an empty file.
    const lines =
        current.linesRead === 0
            ? []
            : [
                  { label: t((d) => d.pages.walletImports.review.statLines), value: current.linesRead },
                  { label: t((d) => d.pages.walletImports.review.statFolded), value: current.linesFolded },
                  { label: t((d) => d.pages.walletImports.review.statSkipped), value: current.linesSkipped },
              ];

    const stats = [
        ...lines,
        { label: t((d) => d.pages.walletImports.review.statTransactions), value: current.rowCount },
        { label: t((d) => d.pages.walletImports.review.statDuplicates), value: current.duplicateCount },
        { label: t((d) => d.pages.walletImports.review.statAi), value: detail.proposals.ai },
        { label: t((d) => d.pages.walletImports.review.statRules), value: detail.proposals.rule },
    ];

    const done = classification?.batches.length ?? 0;
    const expected = classification?.expected ?? 0;
    const waiting = classification !== null && classification.failure === null && done < expected;

    const aiStatus =
        classification === null
            ? null
            : classification.failure !== null
              ? t((d) => d.pages.walletImports.review.aiUnavailable)
              : expected === 0
                ? t((d) => d.pages.walletImports.review.aiNothing)
                : waiting
                  ? t((d) => d.pages.walletImports.review.aiWaiting)
                        .replace('{done}', String(done))
                        .replace('{expected}', String(expected))
                  : t((d) => d.pages.walletImports.review.aiDone).replace('{rows}', String(detail.proposals.ai));

    return (
        <div className="border-border border-b px-4 py-3">
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
                {stats.map((stat) => (
                    <div key={stat.label} className="min-w-0">
                        <dt className="note leading-tight">{stat.label}</dt>
                        <dd className="text-lg font-semibold tabular-nums">{stat.value.toLocaleString(locale)}</dd>
                    </div>
                ))}
            </dl>
            {aiStatus === null ? null : (
                <p className={'mt-3 text-sm' + (waiting ? ' motion-safe:animate-pulse' : '')}>{aiStatus}</p>
            )}
        </div>
    );
}

/**
 * Every call made to suggest categories: what was sent, and what came back.
 *
 * Closed by default — it is for checking why a counterparty got the category it got, not for reading
 * through. It shows exactly the payload that left this application, so "what did we tell the model"
 * never needs answering from memory.
 */
function AiCalls({ classification }: { classification: StatementClassification | null }): JSX.Element | null {
    const { t } = useTranslation();

    if (classification === null || classification.batches.length === 0) {
        return null;
    }

    return (
        <details className="border-border border-b px-4 py-3">
            <summary className="cursor-pointer text-sm font-medium">
                {t((d) => d.pages.walletImports.review.aiCalls)}
            </summary>
            <div className="mt-3 flex flex-col gap-4">
                {classification.batches.map((batch, index) => (
                    <div key={`call-${String(index)}`} className="min-w-0">
                        <div className="text-sm font-medium">
                            {t((d) => d.pages.walletImports.review.aiCall).replace('{number}', String(index + 1))}
                        </div>
                        {batch.failure === null ? null : (
                            <p className="text-destructive text-sm">
                                {t((d) => d.pages.walletImports.review.aiFailed).replace('{reason}', batch.failure)}
                            </p>
                        )}
                        {batch.sent === null ? null : (
                            <>
                                <div className="note mt-2">{t((d) => d.pages.walletImports.review.aiSent)}</div>
                                <pre className="border-border mt-1 max-h-80 overflow-auto rounded-md border p-3 text-xs">
                                    {JSON.stringify(batch.sent, null, 2)}
                                </pre>
                            </>
                        )}
                        {batch.reply === null ? null : (
                            <>
                                <div className="note mt-2">{t((d) => d.pages.walletImports.review.aiReply)}</div>
                                <pre className="border-border mt-1 max-h-80 overflow-auto rounded-md border p-3 text-xs">
                                    {JSON.stringify(batch.reply, null, 2)}
                                </pre>
                            </>
                        )}
                    </div>
                ))}
            </div>
        </details>
    );
}

/**
 * What the confirmed form actually reads, in words.
 *
 * The selects say where each field comes from while you are filling them in, and then the rows
 * appear and that knowledge is gone — leaving a screen of figures and no way to tell whether the
 * counterparty came from a column, a label, or nothing at all. This is that answer, kept beside the
 * rows it explains.
 */
function FormSummary({ detail, form }: { detail: StatementImportDetail; form: StatementForm }): JSX.Element {
    const { t } = useTranslation();

    const columnName = (index: number): string => {
        const column = detail.preview.columns.find((entry) => entry.index === index);

        return column === undefined || column.header === '' ? `#${String(index + 1)}` : column.header;
    };

    // A label is shown with its colon — `Tranzactie la:` is what a person sees in their own file,
    // and it is what distinguishes a label from a column with a similar name.
    const describe = (field: StatementField | null): string =>
        field === null
            ? t((d) => d.pages.walletImports.columns.none)
            : field.type === 'index'
              ? columnName(Number(field.value))
              : field.type === 'label'
                ? `${String(field.value)}:`
                : String(field.value);

    const fields: { label: string; value: string }[] = [
        {
            label: t((d) => d.pages.walletImports.columns.date),
            value: `${columnName(form.date.value)} · ${form.date.format}`,
        },
        ...(form.amounts.mode === 'signed'
            ? [{ label: t((d) => d.pages.walletImports.columns.amount), value: describe(form.amounts.amount) }]
            : [
                  { label: t((d) => d.pages.walletImports.columns.debit), value: describe(form.amounts.debit) },
                  { label: t((d) => d.pages.walletImports.columns.credit), value: describe(form.amounts.credit) },
              ]),
        { label: t((d) => d.pages.walletImports.columns.description), value: describe(form.description) },
        { label: t((d) => d.pages.walletImports.columns.counterpartyOut), value: describe(form.counterparty.debit) },
        { label: t((d) => d.pages.walletImports.columns.counterpartyIn), value: describe(form.counterparty.credit) },
        { label: t((d) => d.pages.walletImports.columns.referenceOut), value: describe(form.reference.debit) },
        { label: t((d) => d.pages.walletImports.columns.referenceIn), value: describe(form.reference.credit) },
        { label: t((d) => d.pages.walletImports.columns.currency), value: describe(form.currency) },
    ];

    return (
        <details className="border-border border-b px-4 py-3">
            <summary className="cursor-pointer text-sm font-medium">
                {t((d) => d.pages.walletImports.columns.summary)}
            </summary>
            <div className="mt-3">
                <DetailList>
                    {fields.map((field) => (
                        <Detail key={field.label} label={field.label}>
                            {field.value}
                        </Detail>
                    ))}
                </DetailList>
            </div>
        </details>
    );
}

/** A field as one string, so a single select can carry a column, a label or a fixed code. */
function encodeField(field: StatementField | null): string {
    return field === null ? '' : `${field.type}:${String(field.value)}`;
}

function decodeField(value: string): StatementField | null {
    const separator = value.indexOf(':');

    if (separator < 0) {
        return null;
    }

    const type = value.slice(0, separator);
    const raw = value.slice(separator + 1);

    if (type === 'index') {
        return { type: 'index', value: Number(raw) };
    }

    return type === 'label' || type === 'code' ? { type, value: raw } : null;
}

/**
 * The form: where each field of a transaction lives.
 *
 * Every field offers the file's columns *and* the labels found on its detail lines, because in a
 * statement written as blocks the counterparty is never in a column. Counterparty and reference are
 * asked once per direction, and each side is offered the labels that actually appear on that side —
 * money out carries one set of labels, money in another, and offering both everywhere is how the
 * wrong one gets picked.
 */
function MappingFields({
    detail,
    mapping,
    onChange,
}: {
    detail: StatementImportDetail;
    mapping: StatementForm;
    onChange: (mapping: StatementForm) => void;
}): JSX.Element {
    const { t } = useTranslation();

    const columnOptions = detail.preview.columns.map((column) => ({
        value: `index:${String(column.index)}`,
        label: `${column.header === '' ? `#${String(column.index + 1)}` : column.header} · ${column.shape}`,
    }));

    const none = { value: '', label: t((d) => d.pages.walletImports.columns.none) };
    const plainColumns = detail.preview.columns.map((column) => ({
        value: String(column.index),
        label: `${column.header === '' ? `#${String(column.index + 1)}` : column.header} · ${column.shape}`,
    }));

    /** The labels seen beneath transactions whose amount sat in this column, most common first. */
    const labelsFor = (column: number | null): { value: string; label: string }[] => {
        const groups = detail.preview.labels;
        const group = column === null ? undefined : groups.find((entry) => entry.amountColumn === column);
        const seen = new Set<string>();

        return (group?.labels ?? groups.flatMap((entry) => entry.labels))
            .filter((label) => {
                if (seen.has(label.label)) {
                    return false;
                }

                seen.add(label.label);

                return true;
            })
            .map((label) => ({
                value: `label:${label.label}`,
                label: `${label.label} · ${String(label.seenInPercent)}%`,
            }));
    };

    const set = (changes: Partial<StatementForm>): void => {
        onChange({ ...mapping, ...changes });
    };

    const setAmounts = (changes: Partial<StatementForm['amounts']>): void => {
        set({ amounts: { ...mapping.amounts, ...changes } });
    };

    const debitColumn = typeof mapping.amounts.debit?.value === 'number' ? mapping.amounts.debit.value : null;
    const creditColumn = typeof mapping.amounts.credit?.value === 'number' ? mapping.amounts.credit.value : null;

    /**
     * One select over the columns and the labels, for a field that could be in either.
     *
     * `codes` adds fixed values the file does not contain at all — which is how a statement states
     * its currency: it is printed once at the top, or nowhere, and never in a column.
     */
    const fieldSelect = (
        field: StatementField | null,
        labels: { value: string; label: string }[],
        onPick: (next: StatementField | null) => void,
        codes: readonly string[] = [],
    ): JSX.Element => {
        const chosen = encodeField(field);
        const options = [
            none,
            ...columnOptions,
            ...labels,
            ...codes.map((code) => ({ value: `code:${code}`, label: code })),
        ];

        return (
            <PanelSelect
                value={chosen}
                onValueChange={(next) => {
                    onPick(decodeField(next));
                }}
                // A code the model proposed — a statement in one currency states it nowhere — is
                // kept as an option so confirming the form does not silently drop it.
                options={
                    chosen !== '' && !options.some((option) => option.value === chosen)
                        ? [...options, { value: chosen, label: String(field?.value ?? '') }]
                        : options
                }
            />
        );
    };

    /** What a person can drop: a heading the file has, or a label whose value is an identifier. */
    const chips = (values: string[], onRemove: (value: string) => void): JSX.Element => (
        <div className="flex flex-wrap gap-2">
            {values.map((value) => (
                <button
                    key={value}
                    type="button"
                    className="rounded-full border border-border px-3 py-1 text-xs text-muted-foreground hover:border-destructive hover:text-destructive"
                    title={t((d) => d.pages.walletImports.columns.removeHint)}
                    onClick={() => {
                        onRemove(value);
                    }}
                >
                    {value} ×
                </button>
            ))}
        </div>
    );

    const suggestionNote = ((): string | null => {
        switch (detail.import.aiFailureReason) {
            case null:
                return null;
            case 'NO_ROOM':
                return t((d) => d.pages.walletImports.columns.suggestionNoRoom);
            case 'UNAVAILABLE':
                return t((d) => d.pages.walletImports.columns.suggestionUnavailable);
            default:
                return t((d) => d.pages.walletImports.columns.suggestionFailed);
        }
    })();

    return (
        <>
            {suggestionNote === null ? null : <p className="mb-4 text-sm text-muted-foreground">{suggestionNote}</p>}

            <FieldGrid>
                <Field label={t((d) => d.pages.walletImports.columns.layout)}>
                    <PanelSelect
                        value={mapping.layout}
                        onValueChange={(next) => {
                            set({ layout: next === 'block' ? 'block' : 'row' });
                        }}
                        options={[
                            { value: 'row', label: t((d) => d.pages.walletImports.columns.layoutRow) },
                            { value: 'block', label: t((d) => d.pages.walletImports.columns.layoutBlock) },
                        ]}
                    />
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.date)}>
                    <PanelSelect
                        value={String(mapping.date.value)}
                        onValueChange={(next) => {
                            set({ date: { ...mapping.date, value: Number(next) } });
                        }}
                        options={plainColumns}
                    />
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.dateFormat)}>
                    <PanelSelect
                        value={mapping.date.format}
                        onValueChange={(next) => {
                            set({ date: { ...mapping.date, format: next } });
                        }}
                        options={DATE_FORMATS.map((format) => ({ value: format, label: format }))}
                    />
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.amountMode)}>
                    <PanelSelect
                        value={mapping.amounts.mode}
                        onValueChange={(next) => {
                            setAmounts({ mode: next === 'debit_credit' ? 'debit_credit' : 'signed' });
                        }}
                        options={[
                            { value: 'signed', label: t((d) => d.pages.walletImports.columns.signed) },
                            { value: 'debit_credit', label: t((d) => d.pages.walletImports.columns.debitCredit) },
                        ]}
                    />
                </Field>

                {mapping.amounts.mode === 'signed' ? (
                    <Field label={t((d) => d.pages.walletImports.columns.amount)}>
                        <PanelSelect
                            value={mapping.amounts.amount === null ? '' : String(mapping.amounts.amount.value)}
                            onValueChange={(next) => {
                                setAmounts({ amount: next === '' ? null : { type: 'index', value: Number(next) } });
                            }}
                            options={plainColumns}
                        />
                    </Field>
                ) : (
                    <>
                        <Field label={t((d) => d.pages.walletImports.columns.debit)}>
                            <PanelSelect
                                value={mapping.amounts.debit === null ? '' : String(mapping.amounts.debit.value)}
                                onValueChange={(next) => {
                                    setAmounts({ debit: next === '' ? null : { type: 'index', value: Number(next) } });
                                }}
                                options={[none, ...plainColumns]}
                            />
                        </Field>
                        <Field label={t((d) => d.pages.walletImports.columns.credit)}>
                            <PanelSelect
                                value={mapping.amounts.credit === null ? '' : String(mapping.amounts.credit.value)}
                                onValueChange={(next) => {
                                    setAmounts({ credit: next === '' ? null : { type: 'index', value: Number(next) } });
                                }}
                                options={[none, ...plainColumns]}
                            />
                        </Field>
                    </>
                )}

                <Field label={t((d) => d.pages.walletImports.columns.decimal)}>
                    <PanelSelect
                        value={mapping.amounts.decimal_separator}
                        onValueChange={(next) => {
                            setAmounts({ decimal_separator: next, thousands_separator: next === ',' ? '.' : ',' });
                        }}
                        options={[
                            { value: ',', label: '1.234,56' },
                            { value: '.', label: '1,234.56' },
                        ]}
                    />
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.description)}>
                    {fieldSelect(mapping.description, labelsFor(null), (next) => {
                        set({ description: next });
                    })}
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.counterpartyOut)}>
                    {fieldSelect(mapping.counterparty.debit, labelsFor(debitColumn), (next) => {
                        set({ counterparty: { ...mapping.counterparty, debit: next } });
                    })}
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.counterpartyIn)}>
                    {fieldSelect(mapping.counterparty.credit, labelsFor(creditColumn), (next) => {
                        set({ counterparty: { ...mapping.counterparty, credit: next } });
                    })}
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.referenceOut)}>
                    {fieldSelect(mapping.reference.debit, labelsFor(debitColumn), (next) => {
                        set({ reference: { ...mapping.reference, debit: next } });
                    })}
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.referenceIn)}>
                    {fieldSelect(mapping.reference.credit, labelsFor(creditColumn), (next) => {
                        set({ reference: { ...mapping.reference, credit: next } });
                    })}
                </Field>

                <Field
                    label={t((d) => d.pages.walletImports.columns.currency)}
                    hint={t((d) => d.pages.walletImports.columns.currencyHint)}
                >
                    {fieldSelect(
                        mapping.currency,
                        labelsFor(null),
                        (next) => {
                            set({ currency: next });
                        },
                        CURRENCIES,
                    )}
                </Field>

                {mapping.non_transaction_lines.length === 0 ? null : (
                    <Field
                        label={t((d) => d.pages.walletImports.columns.dropLines)}
                        hint={t((d) => d.pages.walletImports.columns.dropLinesHint)}
                    >
                        {chips(mapping.non_transaction_lines, (value) => {
                            set({
                                non_transaction_lines: mapping.non_transaction_lines.filter((entry) => entry !== value),
                            });
                        })}
                    </Field>
                )}

                {mapping.identity_labels.length === 0 ? null : (
                    <Field
                        label={t((d) => d.pages.walletImports.columns.identityLabels)}
                        hint={t((d) => d.pages.walletImports.columns.identityLabelsHint)}
                    >
                        {chips(mapping.identity_labels, (value) => {
                            set({ identity_labels: mapping.identity_labels.filter((entry) => entry !== value) });
                        })}
                    </Field>
                )}
            </FieldGrid>
        </>
    );
}
