import { Head, router } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowDownToLine,
    ArrowUpRight,
    ArrowRight,
    Check,
    CalendarDays,
    CalendarRange,
    Coins,
    Eye,
    RotateCcw,
    CheckCircle2,
    Columns3,
    ChevronRight,
    CopyCheck,
    Filter,
    Hash,
    Landmark,
    Loader2,
    FileSpreadsheet,
    FileText,
    ListChecks,
    Rows3,
    Scale,
    Shapes,
    Sparkles,
    TriangleAlert,
    Upload,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { type JSX, useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { listAccounts, listBanks, listTags } from '../../api/walletApi';
import {
    commitStatementImport,
    getStatementImport,
    listStatementImports,
    previewStatementForm,
    requestColumnSuggestion,
    requestRowSuggestions,
    setStatementImportMapping,
    startStatementImport,
    updateStatementImportRows,
} from '../../api/statementImportApi';
import Checkbox from '../../components/Checkbox';
import Pagination from '../../components/Pagination';
import { bankOptions, type BankSelectOption } from '../../components/wallet/bankOptions';
import { ChosenFile, FileDrop } from '../../components/FileDrop';
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
    PanelMultiSelect,
    PanelSelect,
    Stat,
    Pill,
    Status,
    Toolbar,
} from '../../components/panel';
import { useRowSelection } from '../../hooks/useRowSelection';
import { useCurrencies } from '../../lib/sharedProps';
import { useTranslation } from '../../i18n';
import { formatMinor, formatNumber } from '../../lib/format';
import { describeApiFailure } from '../../lib/apiErrors';
import { useActiveSubjectId } from '../../hooks/useActiveSubjectId';
import { cn } from '../../lib/utils';
import { categoriesFor, isStreamKind, type Bank, type Tag } from '../../types/wallet';
import { useImportsChannel } from '../../hooks/useImportsChannel';
import {
    DATE_FORMATS,
    type StatementClassification,
    type StatementConversion,
    type StatementField,
    type StatementForm,
    type StatementFormPreview,
    type StatementImportDetail,
    type StatementImportEvent,
} from '../../types/statementImport';

const IMPORTS_PATH = '/wallet/imports';

const TRANSACTIONS_PATH = '/wallet/transactions';

/** How often the screen asks again while the file is being read or the rows written. */
// Long enough that a select does not fire a request per keystroke, short enough that the rebuilt
// preview feels like it is answering you.
const PREVIEW_DEBOUNCE_MS = 400;

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
    const subjectId = useActiveSubjectId();

    const [importId, setImportId] = useState<string | null>(() => {
        if (typeof window === 'undefined') {
            return null;
        }

        return new URLSearchParams(window.location.search).get('import');
    });
    const [detail, setDetail] = useState<StatementImportDetail | null>(null);
    const [banks, setBanks] = useState<Bank[]>([]);
    const [tags, setTags] = useState<Tag[]>([]);

    // Step 1 — the file. The account is not asked: a statement lands in the general source.
    const [accountId, setAccountId] = useState('');
    const [staged, setStaged] = useState<File[]>([]);
    const [batch, setBatch] = useState<Queued[]>([]);
    const [reached, setReached] = useState<Record<string, string>>({});
    const [starting, setStarting] = useState(false);

    // Step 2 — the columns.
    const [mapping, setMapping] = useState<StatementForm | null>(null);
    // The bank as a person picked it; undefined until they do, so the guess from the file shows.
    const [bankChoice, setBankChoice] = useState<string | null | undefined>(undefined);
    const [applying, setApplying] = useState(false);
    /*
     * Reopening the form once the rows exist.
     *
     * The server allows it — an import stays open through review — and it is the only way to see
     * what was matched to what, or to fix one field without starting the upload again.
     */
    const [remapping, setRemapping] = useState(false);
    const [formPreview, setFormPreview] = useState<StatementFormPreview | null>(null);
    // The escape from waiting on the model: a person who does not want suggestions can review now.
    const [reviewWithoutSuggestions, setReviewWithoutSuggestions] = useState(false);

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
                    const general = usable.find((account) => account.kind === 'GENERAL') ?? usable[0];
                    setAccountId((current) => (current === '' ? (general?.id ?? '') : current));
                }
            },
            () => {
                // No source to land in: the Start button stays disabled rather than failing later.
            },
        );

        listBanks(subjectId, { includeClosed: true }).then(
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

            // One of the others of the batch moved on: the strip says so without being opened.
            setBatch((queue) => {
                if (queue.some((one) => one.importId === event.importId)) {
                    setReached((known) => ({ ...known, [event.importId]: event.status }));
                }

                return queue;
            });
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

    /*
     * Whether the model is still suggesting, so the review is withheld.
     *
     * Showing the rows and an Import button while the calls are still running told a person the work
     * was done — and importing then would skip every suggestion still on its way. So the review waits
     * for the round to be over: every call recorded, or the round recorded as not happening at all.
     */
    const classification = detail?.preview.classification ?? null;
    const suggesting =
        detail?.import.status === 'REVIEW' &&
        !remapping &&
        !reviewWithoutSuggestions &&
        classification !== null &&
        classification.failure === null &&
        (classification.expected === null || classification.batches.length < classification.expected);

    useEffect(() => {
        if (subjectId === null || batch.length < 2) {
            return;
        }

        let active = true;

        listStatementImports(subjectId).then(
            (loaded) => {
                if (active) {
                    setReached(Object.fromEntries(loaded.imports.map((one) => [one.id, one.status])));
                }
            },
            () => {
                // The strip falls back to what the upload itself reported.
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, batch.length]);

    // The next of the batch still to be answered for: not this one, and not one that failed.
    const waiting =
        batch.find(
            (one) =>
                one.importId !== null &&
                one.importId !== importId &&
                one.state === 'queued' &&
                (reached[one.importId] ?? 'READING') !== 'IMPORTED',
        ) ?? null;

    const rows = useMemo(() => detail?.rows ?? [], [detail]);
    const selection = useRowSelection(useMemo(() => rows.map((row) => row.id), [rows]));

    if (subjectId === null) {
        return (
            <>
                <Page title={t((d) => d.pages.walletImports.title)}>
                    <Panel title={t((d) => d.pages.walletImports.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </>
        );
    }

    const status = detail?.import.status ?? null;
    const chosenBank = bankChoice === undefined ? (detail?.import.bankId ?? '') : (bankChoice ?? '');

    /*
     * Every file chosen, sent one after another — two at a time.
     *
     * Each is its own import: its own columns to confirm, its own rows to answer for, read by the
     * queue on its own. So each is its own request too, and a file refused — already imported, too
     * large, unreadable — says so on its own line while the rest carry on. Ten at once would open ten
     * connections and reach the upload limit for nothing; two keeps the queue fed.
     */
    const upload = async (): Promise<void> => {
        if (staged.length === 0 || accountId === '') {
            return;
        }

        const files = [...staged];
        setStarting(true);
        setBatch(files.map((chosen) => ({ name: chosen.name, importId: null, state: 'waiting' })));

        const accepted: string[] = [];
        const mark = (at: number, change: Partial<Queued>): void => {
            setBatch((queue) => queue.map((one, index) => (index === at ? { ...one, ...change } : one)));
        };

        let next = 0;
        const send = async (): Promise<void> => {
            while (next < files.length) {
                const at = next++;
                const chosen = files[at];

                if (chosen === undefined) {
                    return;
                }

                mark(at, { state: 'uploading' });

                try {
                    const started = await startStatementImport(subjectId, chosen, accountId);
                    accepted.push(started.id);
                    mark(at, { state: 'queued', importId: started.id });
                } catch (cause: unknown) {
                    mark(at, { state: 'failed', said: describeApiFailure(cause, t) });
                }
            }
        };

        await Promise.all([send(), send()]);

        setStarting(false);
        setStaged([]);

        /*
         * Somewhere to go only if something is going there.
         *
         * Each accepted file is its own import with its own columns to confirm, and this page can
         * only show one of them — so the list is where they are followed. But a drop where every
         * file was refused (already imported, too large, unreadable) has produced nothing to follow,
         * and sending a person to a list that looks exactly as it did leaves them wondering what
         * happened. The refusals stay on screen instead, one line each.
         */
        if (accepted.length > 0) {
            router.visit(IMPORTS_PATH);
        }
    };

    /*
     * Asking the model again. Both calls return at once; the work runs on the model queue and the
     * import's channel brings the screen along — back through reading to the form, or into the
     * progress card while the calls run.
     */
    const askAgainForColumns = async (): Promise<void> => {
        if (importId === null) {
            return;
        }

        try {
            await requestColumnSuggestion(subjectId, importId);
            setRemapping(false);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.walletImports.columns.askAgainFailed),
                { description: describeApiFailure(cause, t) },
            );
        }
    };

    const askAgainForRows = async (): Promise<void> => {
        if (importId === null) {
            return;
        }

        try {
            await requestRowSuggestions(subjectId, importId);
            // Someone who chose to review without waiting has now asked to wait for these.
            setReviewWithoutSuggestions(false);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            toast.error(describeApiFailure(cause, t));
        }
    };

    const applyMapping = async (): Promise<void> => {
        if (importId === null || mapping === null) {
            return;
        }

        setApplying(true);

        try {
            await setStatementImportMapping(subjectId, importId, mapping, chosenBank === '' ? null : chosenBank);
            setPage(1);
            setRemapping(false);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            // A toast, not a line under the form: the form is long, the button is at its foot, and a
            // sentence below the fold is a refusal a person does not see.
            toast.error(
                t((d) => d.pages.walletImports.columns.notApplied),
                { description: describeApiFailure(cause, t) },
            );
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
        <>
            <Head title={t((d) => d.pages.walletImports.title)} />
            <Page title={t((d) => d.pages.walletImports.title)} lede={t((d) => d.pages.walletImports.description)}>
                {importId !== null && detail === null ? (
                    <Panel title={t((d) => d.pages.walletImports.title)} aside={<Steps status={null} />}>
                        <Working label={t((d) => d.pages.walletImports.opening)} />
                    </Panel>
                ) : null}

                {status === null && importId === null ? (
                    <Panel
                        title={t((d) => d.pages.walletImports.steps.file)}
                        icon={<Upload className="size-4" />}
                        aside={<Steps status={status} />}
                        footActions={
                            <PanelButton
                                primary
                                disabled={starting || staged.length === 0 || accountId === ''}
                                onClick={() => {
                                    void upload();
                                }}
                            >
                                <Upload className="size-3.5" aria-hidden="true" />
                                {starting
                                    ? t((d) => d.pages.walletImports.file.uploading)
                                    : t((d) => d.pages.walletImports.file.startCount).replace(
                                          '{count}',
                                          String(staged.length),
                                      )}
                            </PanelButton>
                        }
                    >
                        <StatementPicker
                            staged={staged}
                            onAdd={(files) => {
                                setStaged((chosen) => [...chosen, ...files]);
                            }}
                            onRemove={(index) => {
                                setStaged((files) => files.filter((_, at) => at !== index));
                            }}
                        />

                        {batch.length === 0 ? null : (
                            <BatchQueue queue={batch} reached={reached} onOpen={setImportId} />
                        )}
                    </Panel>
                ) : null}

                {status === 'READING' || status === 'IMPORTING' ? (
                    <Panel title={t((d) => d.pages.walletImports.title)} aside={<Steps status={status} />}>
                        <Working
                            label={
                                status === 'READING'
                                    ? t((d) => d.pages.walletImports.file.reading)
                                    : t((d) => d.pages.walletImports.review.importing)
                            }
                            note={t((d) => d.pages.walletImports.working)}
                        />
                    </Panel>
                ) : null}

                {status === 'FAILED' ? (
                    <Panel title={t((d) => d.pages.walletImports.failed)} aside={<Steps status={status} />}>
                        <p className="note">
                            {t((d) => d.errors.reasons)[detail?.import.failureReason ?? ''] ??
                                detail?.import.failureReason ??
                                ''}
                        </p>
                        {(detail?.import.importedCount ?? 0) === 0 ? null : (
                            <p className="mt-2 text-sm">
                                {t((d) => d.pages.walletImports.failedPartial).replace(
                                    '{count}',
                                    String(detail?.import.importedCount ?? 0),
                                )}
                            </p>
                        )}
                        <p className="mt-3 flex flex-wrap gap-2">
                            {detail?.import.canReadAgain === true ? (
                                <PanelButton
                                    onClick={() => {
                                        void askAgainForColumns();
                                    }}
                                >
                                    <RotateCcw className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.walletImports.readAgain)}
                                </PanelButton>
                            ) : null}
                            <PanelButton
                                onClick={() => {
                                    router.visit(IMPORTS_PATH);
                                }}
                            >
                                <FileSpreadsheet className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletImports.historyTitle)}
                            </PanelButton>
                        </p>
                    </Panel>
                ) : null}

                {showsForm && mapping !== null ? (
                    <Panel
                        title={t((d) => d.pages.walletImports.columns.title)}
                        shortTitle={t((d) => d.pages.walletImports.steps.columns)}
                        description={t((d) => d.pages.walletImports.columns.hint)}
                        icon={<FileSpreadsheet className="size-4" />}
                        aside={<Steps status={status} />}
                        footActions={
                            <>
                                <PanelButton
                                    disabled={applying}
                                    onClick={() => {
                                        void askAgainForColumns();
                                    }}
                                >
                                    {/* The same mark this screen already gives asking the model
                                        again further down, so the two reads are one gesture. */}
                                    <Sparkles className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.walletImports.columns.askAgain)}
                                </PanelButton>
                                <PanelButton
                                    primary
                                    disabled={applying}
                                    onClick={() => {
                                        void applyMapping();
                                    }}
                                >
                                    {/* The step rail's own separator: on this screen the chevron
                                        already means "the next one of these". */}
                                    <ChevronRight className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.walletImports.columns.apply)}
                                </PanelButton>
                            </>
                        }
                    >
                        {detail.preview.conversion === null ? null : (
                            <ConversionNote conversion={detail.preview.conversion} />
                        )}

                        <MappingFields
                            detail={detail}
                            mapping={mapping}
                            onChange={setMapping}
                            bank={{
                                options: bankOptions(
                                    banks,
                                    t((d) => d.pages.walletTransactions.bank.closed),
                                ),
                                value: chosenBank,
                                source: bankChoice === undefined ? detail.preview.bankSource : null,
                                onChange: setBankChoice,
                            }}
                        />

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

                {suggesting ? (
                    <SuggestionsProgress
                        detail={detail}
                        onSkip={() => {
                            setReviewWithoutSuggestions(true);
                        }}
                    />
                ) : null}

                {status === 'REVIEW' && !remapping && !suggesting && detail !== null ? (
                    <Panel
                        title={t((d) => d.pages.walletImports.review.title)}
                        shortTitle={t((d) => d.pages.walletImports.steps.review)}
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
                                // The server decides how many rows a review page holds, so there is
                                // nothing here for a reader to choose.
                                onPageSizeChange={null}
                            />
                        }
                    >
                        <ReviewOverview
                            detail={detail}
                            onAskAgain={() => {
                                void askAgainForRows();
                            }}
                        />

                        {detail.mapping === null ? null : <FormSummary detail={detail} form={detail.mapping} />}

                        <AiCalls classification={detail.preview.classification} />

                        {/* Which rows to show belongs with what to do about them, so the three controls
                            share one bar: the filter takes the left on a wide screen and the two
                            decisions stay right. On a phone the filter keeps its own line — it is the
                            widest of the three — and goes last, directly above the rows it filters,
                            while the two buttons sit side by side rather than stacked, which cost
                            two rows to say what one row says. */}
                        <div className="review-actions border-border border-b px-4 py-3">
                            <PanelButton
                                className="review-action"
                                onClick={() => {
                                    setRemapping(true);
                                }}
                            >
                                <Columns3 className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletImports.columns.change)}
                            </PanelButton>
                            <PanelButton
                                primary
                                className="review-action"
                                disabled={working}
                                onClick={() => {
                                    void commit();
                                }}
                            >
                                <ArrowDownToLine className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletImports.review.commit).replace(
                                    '{count}',
                                    String(detail.import.rowCount - detail.import.excludedCount),
                                )}
                            </PanelButton>
                            <PanelSelect
                                className="filter-select review-filter"
                                value={filter}
                                placeholder={t((d) => d.pages.walletImports.review.all)}
                                emptyLabel={t((d) => d.pages.walletImports.review.all)}
                                icon={<Filter className="size-3.5" aria-hidden="true" />}
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
                        </div>

                        {/* Only once rows are picked: an empty bar under the actions said a control
                            was coming and never arrived. */}
                        {selection.size === 0 ? null : (
                            <Toolbar>
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
                                        icon={<Shapes className="size-3.5" aria-hidden="true" />}
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
                                        <X className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.walletImports.review.exclude)}
                                    </PanelButton>
                                    <PanelButton
                                        disabled={working}
                                        onClick={() => {
                                            void applyToSelection({ included: true });
                                        }}
                                    >
                                        <Check className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.walletImports.review.include)}
                                    </PanelButton>
                                </>
                            </Toolbar>
                        )}

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
                                    <th className="col-figure">
                                        {t((d) => d.pages.walletTransactions.columns.amount)}
                                    </th>
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
                                    <td className="row-when whitespace-nowrap tabular-nums">
                                        <span className="inline-flex items-center gap-1.5">
                                            {/* Drawn on a phone only. The wide table names this
                                                column in its head, and a calendar on every row
                                                under a column called "Date" says it twice —
                                                `.cell-label` is the class that already appears in
                                                the stacked layout alone. Nested one level down, so
                                                the labelled-cell rules, which match a *direct*
                                                child, stay off and this row keeps its own
                                                arrangement. */}
                                            <span className="cell-label">
                                                <CalendarDays className="size-3.5" aria-hidden="true" />
                                            </span>
                                            {row.bookedAt ?? '—'}
                                        </span>
                                    </td>
                                    <td className="row-name">
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
                                        className={`row-sum col-figure ${
                                            (row.amountMinor ?? 0) < 0 ? 'is-out' : 'is-in'
                                        }`}
                                    >
                                        {row.amountMinor === null
                                            ? '—'
                                            : formatMinor(row.amountMinor, row.currencyCode ?? '', locale)}
                                    </td>
                                    <td className="row-pick">
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

                {/* The rest of the batch, while one of them is open: which still want the columns
                    confirmed, which are still being read, which are done. */}
                {batch.length > 1 && status !== null ? (
                    <BatchQueue queue={batch} reached={reached} open={importId} onOpen={setImportId} />
                ) : null}

                {status === 'IMPORTED' ? (
                    <Panel
                        title={t((d) => d.pages.walletImports.done.title)}
                        shortTitle={t((d) => d.pages.walletImports.steps.done)}
                        aside={<Steps status={status} />}
                    >
                        {/*
                            The two numbers, as numbers.
                            They were a sentence — "529 transactions imported, 0 skipped as already
                            here" — which is the one thing a person came here to see, set as a note
                            under a heading. `Stat` is what this interface already uses for a figure
                            that matters, so the panel reads like the rest of the application rather
                            than like a paragraph.
                        */}
                        <div className="grid grid-cols-2 gap-3">
                            <Stat
                                label={t((d) => d.pages.walletImports.done.imported)}
                                value={
                                    <span className="flex items-center gap-1.5">
                                        <CheckCircle2
                                            className="size-4 text-emerald-600 dark:text-emerald-400"
                                            aria-hidden="true"
                                        />
                                        {String(detail?.import.importedCount ?? 0)}
                                    </span>
                                }
                            />
                            <Stat
                                label={t((d) => d.pages.walletImports.done.skipped)}
                                value={
                                    <span
                                        className={cn(
                                            'flex items-center gap-1.5',
                                            (detail?.import.duplicateCount ?? 0) === 0 && 'text-muted-foreground/60',
                                        )}
                                    >
                                        <CopyCheck
                                            className={cn(
                                                'size-4',
                                                (detail?.import.duplicateCount ?? 0) > 0
                                                    ? 'text-amber-600 dark:text-amber-400'
                                                    : 'text-muted-foreground/50',
                                            )}
                                            aria-hidden="true"
                                        />
                                        {String(detail?.import.duplicateCount ?? 0)}
                                    </span>
                                }
                                // Only when there were any: "nothing was skipped" needs no explaining,
                                // and a line that is always there stops being read.
                                {...((detail?.import.duplicateCount ?? 0) > 0
                                    ? { sub: t((d) => d.pages.walletImports.done.skippedHint) }
                                    : {})}
                            />
                        </div>

                        <div className="mt-4 flex flex-wrap gap-1.5">
                            <PanelButton
                                primary
                                onClick={() => {
                                    router.visit(TRANSACTIONS_PATH);
                                }}
                            >
                                <ArrowRight className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletImports.done.viewTransactions)}
                            </PanelButton>
                            {/* One statement of several: straight on to the next rather than back
                                to the list to find it. */}
                            {waiting === null ? null : (
                                <PanelButton
                                    primary
                                    onClick={() => {
                                        setImportId(waiting.importId);
                                    }}
                                >
                                    <ArrowRight className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.walletImports.file.next)
                                        .replace('{at}', String(batch.indexOf(waiting) + 1))
                                        .replace('{count}', String(batch.length))}
                                </PanelButton>
                            )}
                        </div>
                    </Panel>
                ) : null}
            </Page>
        </>
    );
}

/** One file of a batch on its way in: what it is called, where it got to, and why it did not. */
interface Queued {
    name: string;
    importId: string | null;
    state: 'waiting' | 'uploading' | 'queued' | 'failed';
    said?: string;
}

/** What a statement can be: the same list the picker accepts, so a `.docx` never leaves the screen. */
const STATEMENT_FILES = ['.csv', '.xlsx', '.xls', '.pdf'];

/** The files of a drop or a picker that could be a statement at all. */
/**
 * The files of one batch, each on its own line: uploading, queued, or refused and why.
 *
 * Every file is a separate import with its own columns and its own rows, so this is a way through
 * them rather than a progress bar — the one being worked on is marked, and the others are one click
 * away.
 */
/**
 * The first step's body: where the statements are dropped or chosen, what to know before choosing
 * them, and the ones chosen so far.
 *
 * Chosen, not yet sent: a wrong one is taken off the list here rather than deleted from the
 * documents afterwards. The bank is asked on the next step, where it can already be filled in from
 * the file.
 */
function StatementPicker({
    staged,
    onAdd,
    onRemove,
}: {
    staged: File[];
    onAdd: (files: File[]) => void;
    onRemove: (index: number) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.walletImports.file);

    return (
        <div className="statement-picker">
            <div className="statement-picker-columns">
                <FileDrop
                    id="import-file"
                    accept={STATEMENT_FILES}
                    multiple
                    title={words.drop}
                    choose={words.choose}
                    onFiles={onAdd}
                />

                <aside className="statement-tips">
                    <h3 className="text-sm font-semibold">{words.tipsTitle}</h3>
                    <ul className="statement-tips-list">
                        {[
                            { icon: FileSpreadsheet, tip: words.tips.export },
                            { icon: Check, tip: words.tips.asIs },
                            { icon: ListChecks, tip: words.tips.review },
                        ].map(({ icon: Icon, tip }) => (
                            <li key={tip.title} className="statement-tip">
                                <span className="statement-tip-mark" aria-hidden="true">
                                    <Icon className="size-4" />
                                </span>
                                <span className="min-w-0">
                                    <strong className="block text-xs font-semibold">{tip.title}</strong>
                                    <span className="note">{tip.body}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </aside>
            </div>

            <div className="statement-chosen-head">
                <h3 className="text-sm font-semibold">{words.chosenTitle}</h3>
                <Pill>
                    {(staged.length === 1 ? words.oneFile : words.files).replace('{count}', String(staged.length))}
                </Pill>
            </div>

            {staged.length === 0 ? (
                <p className="statement-chosen-empty">{words.none}</p>
            ) : (
                <ul className="statement-chosen" aria-live="polite">
                    {staged.map((chosen, index) => (
                        <li key={`${chosen.name}-${String(index)}`}>
                            <ChosenFile
                                file={chosen}
                                onRemove={() => {
                                    onRemove(index);
                                }}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function BatchQueue({
    queue,
    reached,
    open,
    onOpen,
}: {
    queue: Queued[];
    /** How far each import has got, by its id — the same words the imports list uses. */
    reached: Record<string, string>;
    open?: string | null;
    onOpen: (importId: string) => void;
}): JSX.Element {
    const { t } = useTranslation();

    return (
        <Panel title={t((d) => d.pages.walletImports.file.batch).replace('{count}', String(queue.length))} flush>
            <ul className="divide-border divide-y">
                {queue.map((one, index) => (
                    <li
                        key={`${one.name}-${String(index)}`}
                        className={cn(
                            'flex items-center gap-2 px-3 py-2',
                            one.importId !== null && one.importId === open && 'bg-muted/50',
                        )}
                    >
                        <FileText className="text-muted-foreground size-3.5 shrink-0" aria-hidden="true" />
                        <span className="min-w-0 flex-1 truncate text-xs font-medium">{one.name}</span>

                        {one.state === 'failed' ? (
                            <Status tone="bad">{one.said ?? t((d) => d.pages.walletImports.failed)}</Status>
                        ) : one.state === 'queued' ? (
                            <Status tone={statusTone(one.importId === null ? null : (reached[one.importId] ?? null))}>
                                {one.importId !== null && reached[one.importId] !== undefined
                                    ? (t((d) => d.pages.walletImports.status)[reached[one.importId] ?? ''] ??
                                      t((d) => d.pages.walletImports.file.queued))
                                    : t((d) => d.pages.walletImports.file.queued)}
                            </Status>
                        ) : (
                            <Status tone="idle">
                                {t((d) =>
                                    one.state === 'uploading'
                                        ? d.pages.walletImports.file.uploading
                                        : d.pages.walletImports.file.waiting,
                                )}
                            </Status>
                        )}

                        {one.importId === null || one.importId === open ? null : (
                            <PanelButton
                                onClick={() => {
                                    onOpen(one.importId ?? '');
                                }}
                            >
                                <Eye className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletImports.file.openOne)}
                            </PanelButton>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/** A statement's stage as a colour: done, gone wrong, or still moving. */
function statusTone(status: string | null): 'ok' | 'bad' | 'warn' | 'idle' {
    if (status === 'IMPORTED') {
        return 'ok';
    }

    if (status === 'FAILED') {
        return 'bad';
    }

    return status === 'MAPPING' || status === 'REVIEW' ? 'warn' : 'idle';
}

/**
 * One number of the review, with what it counts and — where it is a share of something — a bar.
 *
 * A bar rather than a second number: how much of a statement is answered is the one thing a person
 * checks repeatedly while working through it, and a length is read at a glance where a fraction is
 * read a digit at a time.
 */
function Fact({
    icon,
    label,
    value,
    quiet = false,
    marked = false,
    bar = null,
}: {
    icon: JSX.Element;
    label: string;
    value: string;
    /** Nothing to see here: a zero that means "none of this happened" reads smaller. */
    quiet?: boolean;
    /** Done: everything this counts has been answered. */
    marked?: boolean;
    /** How much of it is done, 0 to 1, where that is a question this number answers. */
    bar?: number | null;
}): JSX.Element {
    return (
        <div className={cn('rounded-lg border px-3 py-2', marked ? 'border-success/40 bg-success/10' : 'bg-muted/40')}>
            <p className={cn('note flex items-center gap-1.5', marked && 'text-success')}>
                {icon}
                {label}
            </p>
            <p
                className={cn(
                    'mt-0.5 font-semibold tabular-nums',
                    quiet ? 'text-muted-foreground text-sm' : 'text-lg',
                    marked && 'text-success',
                )}
            >
                {value}
            </p>
            {bar === null ? null : (
                <span className="bg-border mt-1.5 block h-1 overflow-hidden rounded-full">
                    <span
                        className={cn('block h-full rounded-full', marked ? 'bg-success' : 'bg-info')}
                        style={{ width: `${String(Math.round(bar * 100))}%` }}
                    />
                </span>
            )}
        </div>
    );
}

/**
 * Work happening elsewhere, said plainly.
 *
 * A file is read and rows are written on a queue, and the screen is told when it changes rather than
 * asking — so there is no percentage to show honestly. What it can say is what is being done and
 * that it is still being done, which is what three grey rectangles never said.
 */
function Working({ label, note }: { label: string; note?: string }): JSX.Element {
    return (
        <div className="flex flex-col items-center gap-3 py-8 text-center">
            <span className="border-info/30 border-t-info size-8 animate-spin rounded-full border-2" />
            <p className="text-sm font-semibold">{label}</p>
            {note === undefined ? null : <p className="note max-w-sm">{note}</p>}
        </div>
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
        <nav aria-label={t((d) => d.pages.walletImports.steps.progress)}>
            <ol className="flex items-center" role="list">
                {labels.map((label, index) => (
                    <li key={label} className="flex items-center">
                        {/* The step you are on keeps its name on a phone; the others give theirs up.
                            Hiding all four left a row of bare dots that said how far along you were
                            and not what you were doing, and the tinted chip is what makes one of
                            them readable without putting the other three back. */}
                        <div
                            className={index === current ? 'wizard-step is-current' : 'wizard-step'}
                            aria-current={index === current ? 'step' : undefined}
                        >
                            <span
                                className={
                                    index < current
                                        ? 'wizard-dot is-done'
                                        : index === current
                                          ? 'wizard-dot is-now'
                                          : 'wizard-dot'
                                }
                                aria-hidden="true"
                            >
                                {index < current ? <Check className="size-3" /> : index + 1}
                            </span>
                            <span className={index === current ? undefined : 'wizard-label'}>{label}</span>
                        </div>
                        {index === labels.length - 1 ? null : (
                            <ChevronRight className="wizard-sep" aria-hidden="true" />
                        )}
                    </li>
                ))}
            </ol>
        </nav>
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
                                <th className="col-figure">{t((d) => d.pages.walletTransactions.columns.amount)}</th>
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
                                        'col-end col-figure ' + ((row.amount_minor ?? 0) < 0 ? 'is-out' : 'is-in')
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
        // A list, because the text may be split across columns. The guess names one; a person adds
        // the rest when the preview shows half a sentence.
        description: texts[0] === undefined ? [] : [{ type: 'index', value: texts[0].index }],
        // Left empty rather than guessed: which label names the other side of a payment is exactly
        // what a local guess gets wrong, and an empty select asks where a wrong one misleads.
        counterparty: { debit: [], credit: [] },
        reference: { debit: [], credit: [] },
        non_transaction_lines: [],
        identity_labels: [],
    };
}

/**
 * What the screen shows while the model is still suggesting, in place of the review.
 *
 * Progress is counted in calls, because that is the unit that finishes: each call is a batch of
 * counterparties answered at once, and the page is told over the socket as each one lands. Before the
 * calls are planned the number is not known yet, so the bar is shown moving rather than at a made-up
 * percentage.
 *
 * The way out is always on screen. A model that is slow, or a queue that has stopped, must not hold a
 * person's statement hostage — reviewing without suggestions is exactly what they would have done
 * before the model existed.
 */
function SuggestionsProgress({ detail, onSkip }: { detail: StatementImportDetail; onSkip: () => void }): JSX.Element {
    const { t, locale } = useTranslation();
    const classification = detail.preview.classification;
    const expected = classification?.expected ?? null;
    const done = classification?.batches.length ?? 0;
    const percent = expected === null || expected === 0 ? null : Math.min(100, Math.round((done / expected) * 100));

    // The "answered" count is the one that climbs while the screen is open — the others are settled
    // before it starts — so it is the card lit up as the live one, the momentum a person watches.
    const stats: { key: string; icon: LucideIcon; label: string; value: number | null; live?: boolean }[] = [
        { key: 'transactions', icon: Rows3, label: t((d) => d.pages.walletImports.suggesting.transactions), value: detail.import.rowCount },
        {
            key: 'counterparties',
            icon: Users,
            label: t((d) => d.pages.walletImports.suggesting.counterparties),
            value: classification?.counterparties ?? null,
        },
        { key: 'answered', icon: Sparkles, label: t((d) => d.pages.walletImports.suggesting.answered), value: detail.proposals.ai, live: true },
        { key: 'rules', icon: CopyCheck, label: t((d) => d.pages.walletImports.suggesting.rules), value: detail.proposals.rule },
    ];

    return (
        <Panel
            title={t((d) => d.pages.walletImports.suggesting.title)}
            description={t((d) => d.pages.walletImports.suggesting.hint)}
            icon={<Sparkles className="size-4" />}
            aside={<Steps status={detail.import.status} />}
        >
            {/* The model at work, as a mark rather than a bare bar: a sparkle that breathes, haloed
                while it runs, with the running count and the share done beside it. */}
            <div className="flex flex-col items-center gap-4 text-center sm:flex-row sm:text-left">
                <div className="relative grid size-14 shrink-0 place-items-center">
                    <span
                        className="bg-accent/20 absolute inset-0 rounded-full motion-safe:animate-ping"
                        aria-hidden="true"
                    />
                    <span
                        className="bg-accent text-accent-foreground shadow-float relative grid size-14 place-items-center rounded-full"
                    >
                        <Sparkles className="size-6 motion-safe:animate-pulse" aria-hidden="true" />
                    </span>
                </div>

                <div className="min-w-0">
                    <div className="text-sm font-semibold">
                        {expected === null
                            ? t((d) => d.pages.walletImports.suggesting.preparing)
                            : t((d) => d.pages.walletImports.suggesting.progress)
                                  .replace('{done}', String(done))
                                  .replace('{expected}', String(expected))}
                    </div>
                    <p className="note mt-0.5">{t((d) => d.pages.walletImports.suggesting.hint)}</p>
                </div>

                {percent === null ? null : (
                    <div className="text-3xl font-bold tabular-nums sm:ml-auto">{percent}%</div>
                )}
            </div>

            <div
                role="progressbar"
                aria-label={t((d) => d.pages.walletImports.suggesting.progressLabel)}
                aria-valuemin={0}
                aria-valuemax={100}
                {...(percent === null ? {} : { 'aria-valuenow': percent })}
                className="bg-secondary mt-5 h-2.5 w-full overflow-hidden rounded-full"
            >
                <div
                    className={
                        percent === null
                            ? 'bg-accent h-full w-1/3 rounded-full motion-safe:animate-pulse'
                            : 'bg-accent h-full rounded-full motion-safe:transition-[width] motion-safe:duration-500'
                    }
                    style={percent === null ? undefined : { width: `${String(Math.max(percent, 4))}%` }}
                />
            </div>

            <dl className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {stats.map((stat, index) => {
                    const Icon = stat.icon;

                    return (
                        <div
                            key={stat.key}
                            // A staggered rise so the four read as arriving, not blinking in at once.
                            className={cn(
                                'border-border bg-card flex min-w-0 flex-col gap-1.5 rounded-xl border p-3',
                                'motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-2',
                                stat.live && 'border-accent-400 bg-accent/20',
                            )}
                            style={{ animationDelay: `${String(index * 70)}ms`, animationFillMode: 'both' }}
                        >
                            <div className="flex items-center justify-between">
                                <Icon
                                    className={cn('size-3.5', stat.live ? 'text-accent-600 dark:text-accent-400' : 'text-muted-foreground')}
                                    aria-hidden="true"
                                />
                                {stat.live ? (
                                    <span
                                        className="bg-accent-500 size-1.5 rounded-full motion-safe:animate-pulse"
                                        aria-hidden="true"
                                    />
                                ) : null}
                            </div>
                            <dt className="note leading-tight">{stat.label}</dt>
                            <dd className="text-lg font-semibold tabular-nums">
                                {stat.value === null ? '—' : formatNumber(stat.value, locale)}
                            </dd>
                        </div>
                    );
                })}
            </dl>

            <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
                <p className="note">{t((d) => d.pages.walletImports.suggesting.why)}</p>
                <PanelButton onClick={onSkip}>
                    <ArrowRight className="size-3.5" aria-hidden="true" />
                    {t((d) => d.pages.walletImports.suggesting.skip)}
                </PanelButton>
            </div>
        </Panel>
    );
}

/**
 * A model call's failure code, in words. An unknown code is shown as itself rather than hidden:
 * a new failure the screen has no sentence for is still worth seeing.
 */
/**
 * A model call's failure code, in words. An unknown code is shown as itself rather than hidden:
 * a new failure the screen has no sentence for is still worth seeing.
 */
/** Says the table was made by the provider's converter, and passes on what it warned about. */
function ConversionNote({ conversion }: { conversion: StatementConversion }): JSX.Element {
    const { t, locale } = useTranslation();
    const seconds = conversion.milliseconds === null ? '—' : formatNumber(conversion.milliseconds / 1000, locale, 1);

    return (
        <div className="mb-4 space-y-1 text-sm text-muted-foreground">
            <p>
                {t((d) => d.pages.walletImports.columns.converted)
                    .replace('{pipeline}', conversion.pipeline ?? '—')
                    .replace('{seconds}', seconds)}
            </p>
            {conversion.warning === null ? null : (
                <p>
                    {t((d) => d.pages.walletImports.columns.convertedWarning).replace('{warning}', conversion.warning)}
                </p>
            )}
        </div>
    );
}

function aiReason(t: ReturnType<typeof useTranslation>['t'], code: string): string {
    return t((d) => d.pages.walletImports.aiReasons)[code] ?? code;
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
function ReviewOverview({
    detail,
    onAskAgain,
}: {
    detail: StatementImportDetail;
    onAskAgain: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const current = detail.import;
    const classification = detail.preview.classification;

    /*
     * What there is to do, rather than seven numbers in a row.
     *
     * A review screen is opened to answer rows, so the one that matters is how many still want an
     * answer — as a bar, because "0 of 529" and "500 of 529" are the same sentence and a different
     * afternoon. How the file was read is provenance rather than work: one quiet line above, and
     * only for imports that recorded it (older ones have no counts, and zeroes would claim an
     * empty file).
     */
    const answered = detail.proposals.ai + detail.proposals.rule;
    const fileLine =
        current.linesRead === 0
            ? null
            : t((d) => d.pages.walletImports.review.fileLine)
                  .replace('{lines}', formatNumber(current.linesRead, locale))
                  .replace('{folded}', formatNumber(current.linesFolded, locale))
                  .replace('{skipped}', formatNumber(current.linesSkipped, locale));

    const done = classification?.batches.length ?? 0;
    const expected = classification?.expected ?? null;
    const waiting =
        classification !== null && classification.failure === null && (expected === null || done < expected);

    // Calls that came back with a failure — counted, and their reasons named, so a statement two thirds
    // suggested does not read as finished.
    const failedCalls = classification?.batches.filter((batch) => batch.failure !== null) ?? [];
    const failedReasons = [...new Set(failedCalls.map((batch) => aiReason(t, batch.failure ?? '')))].join(', ');
    const withoutSuggestion = Math.max(0, current.rowCount - detail.proposals.ai - detail.proposals.rule);

    const aiStatus =
        classification === null || classification.failure === 'TURNED_OFF'
            ? // Turned off for this installation. Silence was the earlier answer here, on the
              // grounds that nobody needs telling about a setting they chose — but it left a review
              // of blank categories with no account of itself, and "the model is thinking" and "no
              // model will be asked" look identical from the chair. So it says which, and says where
              // the answers that are filled in came from instead.
              t((d) => d.pages.walletImports.review.aiOff)
            : classification.failure === 'UNAVAILABLE'
              ? t((d) => d.pages.walletImports.review.aiUnavailable)
              : classification.failure !== null
                ? t((d) => d.pages.walletImports.review.aiRoundFailed).replace(
                      '{reason}',
                      aiReason(t, classification.failure),
                  )
                : expected === 0
                  ? t((d) => d.pages.walletImports.review.aiNothing)
                  : waiting
                    ? t((d) => d.pages.walletImports.review.aiWaiting)
                          .replace('{done}', String(done))
                          .replace('{expected}', String(expected ?? '…'))
                    : failedCalls.length > 0
                      ? t((d) => d.pages.walletImports.review.aiPartial)
                            .replace('{rows}', String(detail.proposals.ai))
                            .replace('{failed}', String(failedCalls.length))
                            .replace('{calls}', String(expected ?? classification.batches.length))
                            .replace('{reasons}', failedReasons)
                            .replace('{missing}', String(withoutSuggestion))
                      : t((d) => d.pages.walletImports.review.aiDone).replace('{rows}', String(detail.proposals.ai));

    return (
        <div className="border-border border-b px-4 py-3">
            {fileLine === null ? null : <p className="note mb-2.5">{fileLine}</p>}

            <div className="grid gap-2 sm:grid-cols-3">
                <Fact
                    icon={<FileSpreadsheet className="size-3.5" aria-hidden="true" />}
                    label={t((d) => d.pages.walletImports.review.statTransactions)}
                    value={formatNumber(current.rowCount, locale)}
                />
                <Fact
                    icon={<CopyCheck className="size-3.5" aria-hidden="true" />}
                    label={t((d) => d.pages.walletImports.review.statDuplicates)}
                    value={formatNumber(current.duplicateCount, locale)}
                    quiet={current.duplicateCount === 0}
                />
                <Fact
                    icon={<ListChecks className="size-3.5" aria-hidden="true" />}
                    label={t((d) => d.pages.walletImports.review.statAnswered)}
                    value={t((d) => d.pages.walletImports.review.answeredOf)
                        .replace('{answered}', formatNumber(answered, locale))
                        .replace('{total}', formatNumber(current.rowCount, locale))}
                    quiet={answered === 0}
                    marked={answered >= current.rowCount && current.rowCount > 0}
                    bar={current.rowCount === 0 ? null : answered / current.rowCount}
                />
            </div>
            {/* A note rather than a loose sentence: it is one line about where the answers come
                from, and the button beside it acts on exactly that. Always shown — every branch of
                `aiStatus` says something, including the one for a round that was never going to run.

                The mark carries the state, so the sentence does not have to open by naming it: a
                tick where the round is done, a warning where it failed, a muted dot where nothing
                was ever going to be asked. */}
            <div className="ai-note mt-3">
                {waiting ? (
                    <Loader2 className="size-3.5 shrink-0 motion-safe:animate-spin" aria-hidden="true" />
                ) : classification === null || classification.failure === 'TURNED_OFF' ? (
                    <Sparkles className="size-3.5 shrink-0 opacity-60" aria-hidden="true" />
                ) : classification.failure === null ? (
                    <CheckCircle2 className="text-success size-3.5 shrink-0" aria-hidden="true" />
                ) : (
                    <TriangleAlert
                        className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400"
                        aria-hidden="true"
                    />
                )}

                <p className="min-w-0 flex-1">{aiStatus}</p>

                {/* Offered whenever asking again could help: calls failed, the round did not run, or
                    rows are still without a suggestion. Never while calls are still running. */}
                {waiting ||
                (failedCalls.length === 0 && classification?.failure === null && withoutSuggestion === 0) ? null : (
                    <PanelButton onClick={onAskAgain}>
                        <Sparkles className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.walletImports.review.askAgain)}
                    </PanelButton>
                )}
            </div>
        </div>
    );
}
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
                                {t((d) => d.pages.walletImports.review.aiFailed).replace(
                                    '{reason}',
                                    aiReason(t, batch.failure),
                                )}
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
    /** A field's places as one line: "Beneficiar, then Tranzactie la:" — the order they are tried. */
    const describeChain = (chain: StatementField[]): string =>
        chain.length === 0
            ? t((d) => d.pages.walletImports.columns.none)
            : chain.map((place) => describe(place)).join(t((d) => d.pages.walletImports.columns.thenSeparator));

    const describe = (field: StatementField | null): string =>
        field === null
            ? t((d) => d.pages.walletImports.columns.none)
            : field.type === 'index'
              ? columnName(Number(field.value))
              : field.type === 'label'
                ? `${String(field.value)}:`
                : String(field.value);

    /*
     * Three groups rather than one column of eleven.
     *
     * The list used to be every field stacked, label above value, which on a wide screen is a narrow
     * ribbon of text down the left and nothing beside it — and the fields are not one list anyway.
     * "Which column is the date" and "which column names who was paid" are different questions, and a
     * person checking this reads for one of them at a time.
     *
     * Each group is a `DetailList`, and the groups sit in a grid: one column on a phone, two from
     * 640px, three from 1280px. The pairs inside a group stay stacked, because a label and a column
     * name are both short and side by side they would wrap at the width a third of a screen gives.
     */
    const groups: { title: string; icon: JSX.Element; rows: { label: string; value: string }[] }[] = [
        {
            title: t((d) => d.pages.walletImports.columns.groupTransaction),
            icon: <CalendarDays className="size-3.5" aria-hidden="true" />,
            rows: [
                {
                    label: t((d) => d.pages.walletImports.columns.date),
                    value: `${columnName(form.date.value)} · ${form.date.format}`,
                },
                ...(form.amounts.mode === 'signed'
                    ? [{ label: t((d) => d.pages.walletImports.columns.amount), value: describe(form.amounts.amount) }]
                    : [
                          { label: t((d) => d.pages.walletImports.columns.debit), value: describe(form.amounts.debit) },
                          {
                              label: t((d) => d.pages.walletImports.columns.credit),
                              value: describe(form.amounts.credit),
                          },
                      ]),
                { label: t((d) => d.pages.walletImports.columns.currency), value: describe(form.currency) },
            ],
        },
        {
            title: t((d) => d.pages.walletImports.columns.groupCounterparty),
            icon: <Users className="size-3.5" aria-hidden="true" />,
            rows: [
                {
                    label: t((d) => d.pages.walletImports.columns.counterpartyOut),
                    value: describeChain(form.counterparty.debit),
                },
                {
                    label: t((d) => d.pages.walletImports.columns.counterpartyIn),
                    value: describeChain(form.counterparty.credit),
                },
            ],
        },
        {
            title: t((d) => d.pages.walletImports.columns.groupText),
            icon: <FileText className="size-3.5" aria-hidden="true" />,
            rows: [
                {
                    label: t((d) => d.pages.walletImports.columns.description),
                    value: describeChain(form.description),
                },
                {
                    label: t((d) => d.pages.walletImports.columns.referenceOut),
                    value: describeChain(form.reference.debit),
                },
                {
                    label: t((d) => d.pages.walletImports.columns.referenceIn),
                    value: describeChain(form.reference.credit),
                },
            ],
        },
    ];

    return (
        /*
         * Open by default. This is the answer to "did it read my file the way I think it did", asked
         * at the moment a person is deciding whether to trust the preview below it — and a question
         * that has to be clicked open is one most people never ask.
         */
        <details open className="border-border border-b px-4 py-3">
            <summary className="cursor-pointer text-sm font-medium">
                {t((d) => d.pages.walletImports.columns.summary)}
            </summary>

            <div className="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2 xl:grid-cols-3">
                {groups.map((group) => (
                    <section key={group.title} className="min-w-0">
                        <h4 className="eyebrow mb-2 flex items-center gap-1.5">
                            {group.icon}
                            {group.title}
                        </h4>

                        <DetailList>
                            {group.rows.map((row) => (
                                <Detail key={row.label} label={row.label}>
                                    {row.value}
                                </Detail>
                            ))}
                        </DetailList>
                    </section>
                ))}
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
    bank,
}: {
    detail: StatementImportDetail;
    mapping: StatementForm;
    onChange: (mapping: StatementForm) => void;
    /** Which bank issued the statement — not part of the form, but asked beside the currency. */
    bank: {
        options: BankSelectOption[];
        value: string;
        source: 'IBAN' | 'AI' | null;
        onChange: (bankId: string | null) => void;
    };
}): JSX.Element {
    const { t } = useTranslation();
    const currencies = useCurrencies();

    const columnOptions = detail.preview.columns.map((column) => {
        const name = column.header === '' ? `#${String(column.index + 1)}` : column.header;

        return {
            value: `index:${String(column.index)}`,
            // The shape is what tells two number columns apart while choosing, and dead weight once
            // chosen — so a chip shows the name alone. See `PanelMultiSelect`'s `shortLabel`.
            label: `${name} · ${column.shape}`,
            shortLabel: name,
        };
    });

    const none = { value: '', label: t((d) => d.pages.walletImports.columns.none) };
    const chainable = (field: string): boolean => detail.multiSource.fields.includes(field);
    // An empty row waiting to be answered is the screen's business, not the form's: the mapping
    // holds places, and a place nobody has chosen yet is not one.
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
        exclude: readonly string[] = [],
    ): JSX.Element => {
        const chosen = encodeField(field);
        // Built here, so anything that wants to narrow them has to say so: `exclude` is how a chain
        // stops offering a place its other rows already name. Never the empty option, and never this
        // select's own answer, or changing it would mean clearing it first.
        const options = [
            none,
            ...columnOptions,
            ...labels,
            ...codes.map((code) => ({ value: `code:${code}`, label: code })),
        ].filter((option) => option.value === '' || option.value === chosen || !exclude.includes(option.value));

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

    /**
     * A field that may be in several places: the places in order, and a way to name another.
     *
     * One select where the field takes a single answer — which is most of them, and which the server
     * decides (`statement_import.multi_source_fields`). Where several are allowed, each place is a
     * row numbered with the order it is tried in, and an empty row is offered until the ceiling the
     * validator holds, so the screen never offers a place the server would refuse.
     */
    /**
     * A field that may name several places: the places as chips, in the order they are tried.
     *
     * One control rather than a column of selects. A field allowed only one place still renders a
     * plain select, because a chip row for a single answer is a heavier control saying less.
     *
     * Ordered, because for these fields the order is the instruction: the row is read place by place
     * until one has something in it, so "this column, then that one" and its reverse are different
     * mappings. Rearranging is deliberately absent — remove a chip and pick it again.
     */
    const chainSelect = (
        chain: StatementField[],
        labels: { value: string; label: string }[],
        field: string,
        onChange: (next: StatementField[]) => void,
    ): JSX.Element => {
        if (!chainable(field)) {
            return fieldSelect(chain[0] ?? null, labels, (next) => {
                onChange(next === null ? [] : [next]);
            });
        }

        return (
            <PanelMultiSelect
                options={[...columnOptions, ...labels]}
                values={chain.map((place) => encodeField(place))}
                max={detail.multiSource.max}
                addLabel={t((d) => d.pages.walletImports.columns.addPlace)}
                fullLabel={t((d) => d.pages.walletImports.columns.placesFull).replace(
                    ':max',
                    String(detail.multiSource.max),
                )}
                removeLabel={t((d) => d.pages.walletImports.columns.removePlace)}
                placeholder={t((d) => d.pages.walletImports.columns.noPlace)}
                onChange={(next) => {
                    onChange(
                        next.map((value) => decodeField(value)).filter((one): one is StatementField => one !== null),
                    );
                }}
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
                    className="inline-flex items-center gap-1 rounded-full border border-border px-3 py-1 text-xs text-muted-foreground hover:border-destructive hover:text-destructive"
                    title={t((d) => d.pages.walletImports.columns.removeHint)}
                    onClick={() => {
                        onRemove(value);
                    }}
                >
                    {value}
                    <X className="size-3" aria-hidden="true" />
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
                return t((d) => d.pages.walletImports.columns.suggestionFailed).replace(
                    '{reason}',
                    aiReason(t, detail.import.aiFailureReason),
                );
        }
    })();

    return (
        <>
            {suggestionNote === null ? null : <p className="mb-4 text-sm text-muted-foreground">{suggestionNote}</p>}

            <FieldGrid>
                <Field label={t((d) => d.pages.walletImports.columns.layout)}>
                    <PanelSelect
                        icon={<Rows3 className="size-3.5" aria-hidden="true" />}

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
                        icon={<CalendarRange className="size-3.5" aria-hidden="true" />}

                        value={String(mapping.date.value)}
                        onValueChange={(next) => {
                            set({ date: { ...mapping.date, value: Number(next) } });
                        }}
                        options={plainColumns}
                    />
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.dateFormat)}>
                    <PanelSelect
                        icon={<Hash className="size-3.5" aria-hidden="true" />}

                        value={mapping.date.format}
                        onValueChange={(next) => {
                            set({ date: { ...mapping.date, format: next } });
                        }}
                        options={DATE_FORMATS.map((format) => ({ value: format, label: format }))}
                    />
                </Field>

                <Field label={t((d) => d.pages.walletImports.columns.amountMode)}>
                    <PanelSelect
                        icon={<Scale className="size-3.5" aria-hidden="true" />}

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
                            icon={<Coins className="size-3.5" aria-hidden="true" />}

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
                                icon={<ArrowUpRight className="size-3.5" aria-hidden="true" />}

                                value={mapping.amounts.debit === null ? '' : String(mapping.amounts.debit.value)}
                                onValueChange={(next) => {
                                    setAmounts({ debit: next === '' ? null : { type: 'index', value: Number(next) } });
                                }}
                                options={[none, ...plainColumns]}
                            />
                        </Field>
                        <Field label={t((d) => d.pages.walletImports.columns.credit)}>
                            <PanelSelect
                                icon={<ArrowDownLeft className="size-3.5" aria-hidden="true" />}

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
                        icon={<Hash className="size-3.5" aria-hidden="true" />}

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

                <Field
                    label={t((d) => d.pages.walletImports.columns.description)}
                    {...(chainable('description')
                        ? { help: t((d) => d.pages.walletImports.columns.descriptionHint) }
                        : {})}
                >
                    {chainSelect(mapping.description, labelsFor(null), 'description', (next) => {
                        set({ description: next });
                    })}
                </Field>

                <Field
                    label={t((d) => d.pages.walletImports.columns.counterpartyOut)}
                    {...(chainable('counterparty') ? { help: t((d) => d.pages.walletImports.columns.chainHint) } : {})}
                >
                    {chainSelect(mapping.counterparty.debit, labelsFor(debitColumn), 'counterparty', (next) => {
                        set({ counterparty: { ...mapping.counterparty, debit: next } });
                    })}
                </Field>

                <Field
                    label={t((d) => d.pages.walletImports.columns.counterpartyIn)}
                    {...(chainable('counterparty') ? { help: t((d) => d.pages.walletImports.columns.chainHint) } : {})}
                >
                    {chainSelect(mapping.counterparty.credit, labelsFor(creditColumn), 'counterparty', (next) => {
                        set({ counterparty: { ...mapping.counterparty, credit: next } });
                    })}
                </Field>

                {/* A place that repeats the counterparty is passed over here as an empty one is: a
                    reference column holding the payee's name looks like an identity and is not one. */}
                <Field
                    label={t((d) => d.pages.walletImports.columns.referenceOut)}
                    help={t((d) => d.pages.walletImports.columns.referenceHint)}
                >
                    {chainSelect(mapping.reference.debit, labelsFor(debitColumn), 'reference', (next) => {
                        set({ reference: { ...mapping.reference, debit: next } });
                    })}
                </Field>

                <Field
                    label={t((d) => d.pages.walletImports.columns.referenceIn)}
                    help={t((d) => d.pages.walletImports.columns.referenceHint)}
                >
                    {chainSelect(mapping.reference.credit, labelsFor(creditColumn), 'reference', (next) => {
                        set({ reference: { ...mapping.reference, credit: next } });
                    })}
                </Field>

                {/* Required, like the currency: it is part of a transaction's identity — the same
                    reference from two banks is two payments — so a statement without one cannot be
                    read in. */}
                <Field
                    label={t((d) => d.pages.walletImports.columns.bank)}
                    help={
                        bank.source === 'IBAN'
                            ? t((d) => d.pages.walletImports.columns.bankFromIban)
                            : bank.source === 'AI'
                              ? t((d) => d.pages.walletImports.columns.bankFromAi)
                              : t((d) => d.pages.walletImports.columns.bankHint)
                    }
                    error={bank.value === '' ? t((d) => d.pages.walletImports.columns.bankRequired) : undefined}
                    htmlFor="import-bank"
                >
                    <PanelSelect
                        icon={<Landmark className="size-3.5" aria-hidden="true" />}
                        searchable
                        id="import-bank"
                        value={bank.value}
                        placeholder={t((d) => d.pages.walletTransactions.bank.none)}
                        emptyLabel={t((d) => d.pages.walletTransactions.bank.none)}
                        onValueChange={(next) => {
                            bank.onChange(next === '' ? null : next);
                        }}
                        optionVisual="bare"
                        options={bank.options}
                    />
                </Field>

                {/* A currency, not a place to read one from: a statement prints it once at the top
                    or nowhere at all, never in a column, so offering columns here only invited the
                    wrong answer. */}
                <Field
                    label={t((d) => d.pages.walletImports.columns.currency)}
                    help={t((d) => d.pages.walletImports.columns.currencyHint)}
                    error={
                        mapping.currency === null ? t((d) => d.pages.walletImports.columns.currencyRequired) : undefined
                    }
                    htmlFor="import-currency"
                >
                    <PanelSelect
                        icon={<Coins className="size-3.5" aria-hidden="true" />}
                        searchable
                        id="import-currency"
                        value={mapping.currency?.type === 'code' ? String(mapping.currency.value) : ''}
                        placeholder={t((d) => d.pages.walletImports.columns.none)}
                        emptyLabel={t((d) => d.pages.walletImports.columns.none)}
                        onValueChange={(next) => {
                            set({ currency: next === '' ? null : { type: 'code', value: next } });
                        }}
                        options={currencies.map((code) => ({ value: code, label: code }))}
                    />
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
