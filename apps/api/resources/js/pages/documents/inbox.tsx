import { Head, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import Checkbox from '../../components/Checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '../../components/ui/dropdown-menu';
import {
    Building2,
    ChevronDown,
    FileQuestion,
    FileText,
    FolderOpen,
    Gavel,
    Image as ImageIcon,
    Landmark,
    Layers3,
    PiggyBank,
    Plus,
    ReceiptText,
    SearchX,
    Trash2,
} from 'lucide-react';
import Pagination from '../../components/Pagination';
import { toast } from 'sonner';
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';

import { deleteDocument, listDocuments, type DocumentListFilters } from '../../api/documentsApi';
import AppShell from '../../components/AppShell';
import ConfirmDialog from '../../components/ConfirmDialog';
import AddDocumentDialog from '../../components/documents/AddDocumentDialog';
import {
    DataTable,
    Page,
    ActionGroup,
    Panel,
    EmptyState,
    PanelButton,
    PanelSelect,
    Status,
    Toolbar,
    type StatusTone,
} from '../../components/panel';
import { PanelDateRange } from '../../components/PanelDatePicker';
import { Skeleton } from '../../components/ui/skeleton';
import { useCoalescedCallback } from '../../hooks/useCoalescedCallback';
import { useDocumentsChannel } from '../../hooks/useDocumentsChannel';
import { useTranslation } from '../../i18n';
import { formatBytes, formatDateTime } from '../../lib/format';
import { listKnownDocuments, type KnownDocument } from '../../lib/db';
import { getActiveSubjectId } from '../../lib/subjectStore';
import {
    DOCUMENT_STAGES,
    DOCUMENT_TYPES,
    type DocumentLifecycleEvent,
    type DocumentListItem,
    type DocumentStage,
    type DocumentStatus,
    type DocumentType,
} from '../../types/documents';

/**
 * Every document this tax subject owns.
 *
 * ## Where the list comes from, and why that changed
 *
 * From the server. It used to come from this browser's own IndexedDB — the records the *uploading
 * device* happened to keep — which made the screen lie in two directions: a document captured on a
 * phone never appeared on a laptop, and clearing site data emptied the list while the documents
 * themselves sat safely in the object store, now unreachable. A list of what a person owns has to
 * come from the system that owns it.
 *
 * The local database still earns its place, at the job it is actually good at: when the fetch fails
 * because the device is offline, the last-known rows are shown with a notice saying so. Cached, and
 * labelled as cached — never presented as the current truth.
 *
 * ## Live updates
 *
 * Reverb announces each lifecycle change on the subject's documents channel, and the screen
 * re-reads the list over HTTPS rather than trusting the broadcast payload (`BLUEPRINT.md` §16).
 * That is also why there is no polling here.
 */
export default function DocumentsInbox(): JSX.Element {
    const { t, locale } = useTranslation();
    const [subjectId] = useState(() => getActiveSubjectId());

    const [stage, setStage] = useState<DocumentStage | ''>('');
    const [type, setType] = useState<DocumentType | ''>('');
    const [receivedFrom, setReceivedFrom] = useState('');
    const [receivedTo, setReceivedTo] = useState('');
    const [reloadKey, setReloadKey] = useState(0);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<DocumentListItem | null>(null);
    /**
     * Ids ticked for a bulk action.
     *
     * Kept across pages and filter changes on purpose: selecting ten documents, paging to find an
     * eleventh and losing the first ten is the behaviour people expect least. Cleared only when the
     * action they were selected for completes.
     */
    const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());
    const [bulkDeleting, setBulkDeleting] = useState(false);
    const [confirmingBulkDelete, setConfirmingBulkDelete] = useState(false);

    // Rows are stored together with the filters that produced them, so "the loaded filters do not
    // match the current ones" is what means *loading*. Resetting to null inside the effect that
    // fetches would be a state update during render's effect phase, which is what
    // `react-hooks/set-state-in-effect` exists to prevent.
    const [loaded, setLoaded] = useState<{
        key: string;
        rows: DocumentListItem[];
        /** Rows matching the current filters across every page, for the pager's summary. */
        total: number;
        /** True when the server could not be reached and these rows came from the local cache. */
        stale: boolean;
    } | null>(null);
    const [failed, setFailed] = useState(false);

    // "Nothing here" and "nothing matches" are different situations with different remedies.
    const hasFilters =
        stage !== '' || type !== '' || receivedFrom !== '' || receivedTo !== '';

    const filters: DocumentListFilters = {
        ...(stage === '' ? {} : { stage }),
        ...(type === '' ? {} : { type }),
        ...(receivedFrom === '' ? {} : { receivedFrom }),
        ...(receivedTo === '' ? {} : { receivedTo }),
    };

    const filterKey = JSON.stringify([stage, type, receivedFrom, receivedTo, page, pageSize, reloadKey]);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listDocuments(subjectId, filters, page, pageSize).then(
            (result) => {
                if (active) {
                    setLoaded({ key: filterKey, rows: result.data, total: result.total, stale: false });
                    setFailed(false);
                }
            },
            () => {
                if (!active) {
                    return;
                }

                // Offline, or the server is unreachable. Fall back to whatever this device cached,
                // and say so — an empty screen would claim the person owns no documents.
                void listKnownDocuments(subjectId).then(
                    (cached) => {
                        if (active) {
                            setLoaded({ key: filterKey, rows: cached.map(fromCache), total: cached.length, stale: true });
                            setFailed(cached.length === 0);
                        }
                    },
                    () => {
                        if (active) {
                            setLoaded({ key: filterKey, rows: [], total: 0, stale: true });
                            setFailed(true);
                        }
                    },
                );
            },
        );

        return () => {
            active = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `filterKey` is the serialised form of every filter
    }, [subjectId, filterKey]);

    const reload = useCoalescedCallback(() => {
        setReloadKey((current) => current + 1);
    });

    /**
     * How far through the pipeline each document was last seen.
     *
     * `progress` is monotonic across the lifecycle events (upload, scan, extract, propose), so it
     * doubles as a sequence number — which is what makes patching a row from the payload safe
     * despite events being able to arrive out of order. An event carrying less progress than one
     * already applied is stale and dropped.
     */
    const progressSeen = useRef<Map<string, number>>(new Map());

    /**
     * Which documents are currently on screen.
     *
     * A ref, not the `loaded` state itself, so the event handler below keeps a stable identity —
     * `useDocumentsChannel` resubscribes whenever its handler changes, and depending on `loaded`
     * would tear down and rebuild the websocket subscription on every list load.
     */
    const knownIds = useRef<Set<string>>(new Set());

    useEffect(() => {
        knownIds.current = new Set(loaded?.rows.map((row) => row.id) ?? []);
    }, [loaded]);

    /**
     * A document's status moved.
     *
     * A row already on screen is patched from the payload, which carries exactly what changed —
     * no request at all. Only a document this screen has never seen needs the server, and those
     * calls are coalesced, because uploading five files announces five new documents at once.
     *
     * This used to re-read the whole list on every event, filters and all: five uploads meant
     * roughly twenty identical list queries as each document walked through the pipeline.
     */
    const onLifecycle = useCallback(
        (event: DocumentLifecycleEvent) => {
            const lastSeen = progressSeen.current.get(event.documentId);

            if (lastSeen !== undefined && event.progress < lastSeen) {
                return;
            }

            progressSeen.current.set(event.documentId, event.progress);

            // Read from a ref rather than from inside the state updater: React does not promise to
            // run an updater synchronously, so a flag it sets cannot be tested on the next line.
            if (!knownIds.current.has(event.documentId)) {
                reload();

                return;
            }

            setLoaded((current) =>
                current === null
                    ? current
                    : {
                          ...current,
                          rows: current.rows.map((row) =>
                              row.id === event.documentId
                                  ? { ...row, status: event.status, rejectionReason: event.rejectionReason }
                                  : row,
                          ),
                      },
            );
        },
        [reload],
    );

    useDocumentsChannel(subjectId, onLifecycle);

    if (subjectId === null) {
        return (
            <AppShell>
                <Head title={t((d) => d.documentsInbox.title)} />
                <Page title={t((d) => d.documentsInbox.title)}>
                    <Panel title={t((d) => d.documentsInbox.title)}>
                        <p className="note">{t((d) => d.documentsInbox.noActiveSubject)}</p>
                        <p className="mt-2">
                            <Link href="/dashboard" className="underline">
                                {t((d) => d.documentsInbox.goToDashboard)}
                            </Link>
                        </p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    const current = loaded !== null && loaded.key === filterKey ? loaded : null;
    const documents = current?.rows ?? null;

    const pageIds = documents?.map((document) => document.id) ?? [];
    const selectedOnPage = pageIds.filter((id) => selected.has(id)).length;
    const allOnPageSelected = pageIds.length > 0 && selectedOnPage === pageIds.length;

    function toggleOne(id: string, next: boolean): void {
        setSelected((current) => {
            const updated = new Set(current);

            if (next) {
                updated.add(id);
            } else {
                updated.delete(id);
            }

            return updated;
        });
    }

    /** Select-all acts on this page only — it cannot tick rows nobody has seen. */
    function togglePage(next: boolean): void {
        setSelected((current) => {
            const updated = new Set(current);

            for (const id of pageIds) {
                if (next) {
                    updated.add(id);
                } else {
                    updated.delete(id);
                }
            }

            return updated;
        });
    }

    async function deleteSelected(): Promise<void> {
        if (!subjectId) {
            return;
        }

        setBulkDeleting(true);

        try {
            // One request each: there is no bulk endpoint, and inventing one would skip the audit
            // event and the object-store cleanup each single delete already does correctly.
            // Sequential rather than parallel so a partial failure leaves a coherent list.
            for (const id of selected) {
                await deleteDocument(subjectId, id);
            }
        } finally {
            setSelected(new Set());
            setBulkDeleting(false);
            setReloadKey((current) => current + 1);
        }
    }

    const resetFilters = (): void => {
        setPage(1);
        setStage('');
        setType('');
        setReceivedFrom('');
        setReceivedTo('');
    };

    return (
        <AppShell>
            <Head title={t((d) => d.documentsInbox.title)} />
            <Page
                title={t((d) => d.documentsInbox.title)}
                lede={t((d) => d.documentsInbox.description)}
            >
                <Panel
                    flush
                    icon={<FolderOpen className="size-4" />}
                    title={t((d) => d.documentsInbox.title)}
                    description={t((d) => d.documentsInbox.count).replace(
                        '{count}',
                        String(current?.total ?? 0),
                    )}
                    aside={
                        <div className="flex items-center gap-2">
                            {current?.stale === true && (
                                <Status tone="warn">{t((d) => d.documentsInbox.offline)}</Status>
                            )}
                            <PanelButton
                                primary
                                inline
                                onClick={() => {
                                    setAdding(true);
                                }}
                            >
                                <Plus className="size-4" />
                                {t((d) => d.documentsInbox.newDocument)}
                            </PanelButton>
                        </div>
                    }
                    foot={
                        <Pagination
                            page={page}
                            pageSize={pageSize}
                            total={current?.total ?? 0}
                            unit={t((d) => d.documentsInbox.unit)}
                            onPageChange={setPage}
                            onPageSizeChange={(next) => {
                                setPageSize(next);
                                setPage(1);
                            }}
                        />
                    }
                >
                    <Toolbar>
                        <PanelSelect
                            className="filter-select"
                            value={stage}
                            onValueChange={(value) => {
                                setStage(value as DocumentStage | '');
                            }}
                            placeholder={t((d) => d.documentsInbox.filters.allStatuses)}
                            emptyLabel={t((d) => d.documentsInbox.filters.allStatuses)}
                            emptyIcon={<span className="select-dot text-muted-foreground" />}
                            options={DOCUMENT_STAGES.map((value) => ({
                                value,
                                label: t((d) => d.documentStage[value]),
                                // The same dot the table draws, in the same tone — so the filter
                                // and the column it filters read as one vocabulary.
                                icon: <span className={cn('select-dot', statusToneClass(stageTone(value)))} />,
                            }))}
                        />
                        <PanelSelect
                            className="filter-select"
                            value={type}
                            onValueChange={(value) => {
                                setType(value as DocumentType | '');
                            }}
                            placeholder={t((d) => d.documentsInbox.filters.allTypes)}
                            emptyLabel={t((d) => d.documentsInbox.filters.allTypes)}
                            emptyIcon={<Layers3 className="size-4" aria-hidden="true" />}
                            emptyDescription={t((d) => d.documentsInbox.filters.everyDocument)}
                            searchable
                            optionVisual="tile"
                            options={DOCUMENT_TYPES.map((value) => ({
                                value,
                                label: t((d) => d.documentType[value]),
                                // What the type is for, under its name — "Receipt" alone does not
                                // tell someone filing a parking ticket whether it belongs here.
                                description: t((d) => d.documentTypeHint[value]),
                                icon: <DocumentTypeIcon type={value} />,
                            }))}
                        />
                        {/* One control, not two boxes: "received between" is a single thought, and
                            a pair of bare date fields made the reader supply the relationship
                            themselves — as well as making "last month" six clicks instead of one. */}
                        <PanelDateRange
                            title={t((d) => d.documentsInbox.filters.received)}
                            value={{
                                from: receivedFrom === '' ? null : receivedFrom,
                                to: receivedTo === '' ? null : receivedTo,
                            }}
                            onChange={(next) => {
                                setReceivedFrom(next.from ?? '');
                                setReceivedTo(next.to ?? '');
                            }}
                        />
                        <PanelButton onClick={resetFilters}>{t((d) => d.documentsInbox.filters.reset)}</PanelButton>

                        {/* Only once something is ticked. A permanently-present "0 selected" button
                            is a control that does nothing most of the time, and the row it sits in
                            is already full of filters. */}
                        {selected.size > 0 && (
                            <div className="sm:ml-auto">
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button type="button" className="shell-control" disabled={bulkDeleting}>
                                            {t((d) => d.documentsInbox.selectedCount).replace(
                                                '{count}',
                                                String(selected.size),
                                            )}
                                            <ChevronDown className="text-muted-foreground size-3" aria-hidden="true" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-44">
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => {
                                                setConfirmingBulkDelete(true);
                                            }}
                                        >
                                            <Trash2 className="size-4" />
                                            {t((d) => d.documentsInbox.deleteSelected)}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        )}
                    </Toolbar>

                    {documents === null ? (
                        <TableSkeleton />
                    ) : failed ? (
                        <p className="note p-4">{t((d) => d.documentsInbox.loadFailed)}</p>
                    ) : documents.length === 0 ? (
                        hasFilters ? (
                            <EmptyState
                                icon={<SearchX className="size-5" />}
                                title={t((d) => d.documentsInbox.emptyFiltered)}
                                action={
                                    <PanelButton onClick={resetFilters}>
                                        {t((d) => d.documentsInbox.filters.reset)}
                                    </PanelButton>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={<FolderOpen className="size-5" />}
                                title={t((d) => d.documentsInbox.emptyTitle)}
                                description={t((d) => d.documentsInbox.empty)}
                                action={
                                    <PanelButton
                                        primary
                                        inline
                                        onClick={() => {
                                            setAdding(true);
                                        }}
                                    >
                                        <Plus className="size-4" />
                                        {t((d) => d.documentsInbox.newDocument)}
                                    </PanelButton>
                                }
                            />
                        )
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th className="w-10">
                                        <Checkbox
                                            checked={allOnPageSelected}
                                            indeterminate={selectedOnPage > 0 && !allOnPageSelected}
                                            onCheckedChange={togglePage}
                                            aria-label={t((d) => d.documentsInbox.selectPage)}
                                        />
                                    </th>
                                    <th>{t((d) => d.documentsInbox.columns.document)}</th>
                                    <th>{t((d) => d.documentsInbox.columns.type)}</th>
                                    <th>{t((d) => d.documentsInbox.columns.received)}</th>
                                    <th>{t((d) => d.documentsInbox.columns.size)}</th>
                                    <th>{t((d) => d.documentsInbox.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {documents.map((document) => {
                                // Read out of the row before the JSX so the null check narrows it —
                                // indexing the dictionary with `reason ?? ''` would widen the key
                                // back to a string the dictionary has no entry for.
                                const reason = document.rejectionReason;

                                return (
                                    <tr
                                        key={document.id}
                                        className={cn(selected.has(document.id) && 'row-selected')}
                                    >
                                        <td>
                                            <Checkbox
                                                checked={selected.has(document.id)}
                                                onCheckedChange={(next) => {
                                                    toggleOne(document.id, next);
                                                }}
                                                aria-label={document.filename}
                                            />
                                        </td>
                                        <td className="col-primary">
                                            <div className="flex items-center gap-2.5">
                                                <span className="row-mark">
                                                    {document.mimeType === 'application/pdf' ? (
                                                        <FileText className="size-4" aria-hidden="true" />
                                                    ) : (
                                                        <ImageIcon className="size-4" aria-hidden="true" />
                                                    )}
                                                </span>
                                                <div className="min-w-0">
                                                    <div className="truncate">
                                                        {document.filename === ''
                                                            ? t((d) => d.documentType[document.type])
                                                            : document.filename}
                                                    </div>
                                                    {reason !== null && (
                                                        <div className="text-destructive row-note">
                                                            {t((d) => d.rejectionReason[reason])}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap">{t((d) => d.documentType[document.type])}</td>
                                        <td className="col-hide whitespace-nowrap">
                                            <time dateTime={document.receivedAt} className="tabular-nums">
                                                {formatDateTime(document.receivedAt, locale)}
                                            </time>
                                        </td>
                                        <td className="col-hide tabular-nums whitespace-nowrap">
                                            {formatBytes(document.sizeBytes, locale)}
                                        </td>
                                        <td>
                                            <span
                                                className={cn('status-dot', statusToneClass(stageTone(document.stage)))}
                                            >
                                                {t((d) => d.documentStage[document.stage])}
                                            </span>
                                        </td>
                                        <td className="col-end text-right">
                                            <div className="flex justify-end">
                                                <ActionGroup
                                                    actions={[
                                                        ...(document.stage === 'ACTION_NEEDED' ||
                                                        document.stage === 'DONE'
                                                            ? [
                                                                  {
                                                                      key: 'review',
                                                                      label: t((d) =>
                                                                          document.stage === 'DONE'
                                                                              ? d.documentsInbox.view
                                                                              : d.documentsInbox.review,
                                                                      ),
                                                                      href: `/documents/${document.id}/review`,
                                                                  },
                                                              ]
                                                            : []),
                                                        {
                                                            key: 'delete',
                                                            label: <Trash2 className="size-3.5" />,
                                                            title: t((d) => d.common.delete),
                                                            tone: 'destructive' as const,
                                                            onClick: () => {
                                                                setDeleting(document);
                                                            },
                                                        },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {/* Rendered only while open so the upload queue's effects do not run on every page
                view, and re-reads the list on close: a document that finished uploading while the
                dialog was open should be on the screen behind it. */}
            {confirmingBulkDelete && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setConfirmingBulkDelete(false);
                        }
                    }}
                    title={t((d) => d.documentsInbox.deleteSelectedTitle)}
                    description={t((d) => d.documentsInbox.deleteSelectedHint)}
                    detail={
                        <p className="note">
                            {t((d) => d.documentsInbox.selectedCount).replace('{count}', String(selected.size))}
                        </p>
                    }
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        await deleteSelected();
                        setConfirmingBulkDelete(false);
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
                    title={t((d) => d.documentsInbox.deleteTitle)}
                    description={t((d) => d.documentsInbox.deleteHint)}
                    detail={<p className="note break-all">{deleting.filename}</p>}
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await deleteDocument(subjectId, deleting.id);
                            toast.success(t((d) => d.documentsInbox.deleted));
                            setDeleting(null);
                            setReloadKey((current) => current + 1);
                        } catch (cause: unknown) {
                            toast.error(t((d) => d.common.deleteFailed));

                            throw cause;
                        }
                    }}
                />
            )}

            {adding ? (
                <AddDocumentDialog
                    subjectId={subjectId}
                    open={adding}
                    onOpenChange={setAdding}
                    // Driven by the upload itself rather than by closing the dialog: an upload that
                    // succeeded is the thing that changes the list, and it is also the one signal
                    // that still works when Reverb is unreachable. Coalesced with everything else,
                    // so picking eight files still costs one query.
                    onUploaded={reload}
                />
            ) : null}
        </AppShell>
    );
}

/**
 * A cached row shaped like a server row.
 *
 * The two fields the cache cannot know are filled honestly rather than guessed: it never stored the
 * filename (the old inbox showed the document *type* as the heading), and it has no idea how many
 * extractions are waiting, so it claims none rather than inventing a number. The rows are labelled
 * as cached wherever they are shown.
 */

/** The stage a cached row can honestly claim, knowing nothing about reviews or transactions. */
function cachedStage(status: DocumentStatus): DocumentStage {
    return {
        QUARANTINE: 'CHECKING',
        SCANNED: 'QUEUED',
        PROCESSING: 'PROCESSING',
        READY: 'ACTION_NEEDED',
        REJECTED: 'REJECTED',
    }[status] as DocumentStage;
}

function fromCache(document: KnownDocument): DocumentListItem {
    return {
        id: document.id,
        filename: '',
        type: document.type,
        status: document.status,
        // The cache has no idea whether anybody has acted on this document, and guessing `DONE`
        // would hide work. An in-flight status still maps honestly; a `READY` one claims it needs
        // attention, which is the safe direction to be wrong in.
        stage: cachedStage(document.status),
        rejectionReason: document.rejectionReason,
        mimeType: document.mimeType,
        sizeBytes: document.sizeBytes,
        source: 'USER_UPLOAD',
        receivedAt: document.receivedAt,
    };
}

/** Where a document sits in the pipeline, as a colour. Rejected is bad; everything else is progress. */
/**
 * A status tone as a text colour for the dot-and-label form.
 *
 * `Status` renders a filled pill, which is right for a card header and wrong for a table column —
 * a column of pills is a column of boxes. Here the dot carries the colour and the label stays text,
 * so the eye reads the words and the colour only qualifies them.
 */
/**
 * The mark for a document type, shared by the type filter and anywhere else a type is offered.
 *
 * A picker of seven similar words is hard to scan; a shape per type gives the eye something to aim
 * at before it reads. Defined once so the same type never appears under two different marks.
 */
function DocumentTypeIcon({ type }: { type: DocumentType }): JSX.Element {
    const Icon = {
        RECEIPT: ReceiptText,
        INVOICE: FileText,
        BANK_STATEMENT: Landmark,
        DEPOSIT_AGREEMENT: PiggyBank,
        RENTAL_CONTRACT: Building2,
        ANAF_DECISION: Gavel,
        OTHER: FileQuestion,
    }[type];

    return <Icon className="size-4" aria-hidden="true" />;
}

/**
 * A stage as a tone.
 *
 * `ACTION_NEEDED` is `warn` and `DONE` is `ok` — the opposite of how the pipeline sees them, and
 * correct: a document the machine has finished with is precisely the one still asking something of
 * a person, and the green one is the one nobody has to look at again. In-flight stages are `idle`
 * because there is nothing to do about them but wait.
 */
function stageTone(stage: DocumentStage): StatusTone {
    return {
        CHECKING: 'idle',
        QUEUED: 'idle',
        PROCESSING: 'idle',
        ACTION_NEEDED: 'warn',
        DONE: 'ok',
        REJECTED: 'bad',
    }[stage] as StatusTone;
}


function statusToneClass(tone: StatusTone): string {
    return {
        ok: 'text-success',
        warn: 'text-warning',
        bad: 'text-destructive',
        idle: 'text-muted-foreground',
    }[tone];
}

function TableSkeleton(): JSX.Element {
    return (
        <div className="space-y-2 p-3">
            {[0, 1, 2, 3, 4].map((index) => (
                <Skeleton key={index} className="tile h-9 w-full" />
            ))}
        </div>
    );
}
