import { Head, Link } from '@inertiajs/react';
import { toast } from 'sonner';
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';

import { deleteDocument, listDocuments, type DocumentListFilters } from '../../api/documentsApi';
import AppShell from '../../components/AppShell';
import ConfirmDialog from '../../components/ConfirmDialog';
import AddDocumentDialog from '../../components/documents/AddDocumentDialog';
import {
    DataTable,
    Page,
    Panel,
    PanelButton,
    PanelInput,
    PanelSelect,
    Status,
    Toolbar,
    type StatusTone,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { Switch } from '../../components/ui/switch';
import { useCoalescedCallback } from '../../hooks/useCoalescedCallback';
import { useDocumentsChannel } from '../../hooks/useDocumentsChannel';
import { useTranslation } from '../../i18n';
import { formatBytes, formatDateTime } from '../../lib/format';
import { listKnownDocuments, type KnownDocument } from '../../lib/db';
import { getActiveSubjectId } from '../../lib/subjectStore';
import {
    DOCUMENT_STATUSES,
    DOCUMENT_TYPES,
    type DocumentLifecycleEvent,
    type DocumentListItem,
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

    const [status, setStatus] = useState<DocumentStatus | ''>('');
    const [type, setType] = useState<DocumentType | ''>('');
    const [receivedFrom, setReceivedFrom] = useState('');
    const [receivedTo, setReceivedTo] = useState('');
    const [needsReview, setNeedsReview] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<DocumentListItem | null>(null);

    // Rows are stored together with the filters that produced them, so "the loaded filters do not
    // match the current ones" is what means *loading*. Resetting to null inside the effect that
    // fetches would be a state update during render's effect phase, which is what
    // `react-hooks/set-state-in-effect` exists to prevent.
    const [loaded, setLoaded] = useState<{
        key: string;
        rows: DocumentListItem[];
        nextCursor: string | null;
        /** True when the server could not be reached and these rows came from the local cache. */
        stale: boolean;
    } | null>(null);
    const [loadingMore, setLoadingMore] = useState(false);
    const [failed, setFailed] = useState(false);

    const filters: DocumentListFilters = {
        ...(status === '' ? {} : { status }),
        ...(type === '' ? {} : { type }),
        ...(receivedFrom === '' ? {} : { receivedFrom }),
        ...(receivedTo === '' ? {} : { receivedTo }),
        ...(needsReview ? { needsReview: true } : {}),
    };

    const filterKey = JSON.stringify([status, type, receivedFrom, receivedTo, needsReview, reloadKey]);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listDocuments(subjectId, filters).then(
            (page) => {
                if (active) {
                    setLoaded({ key: filterKey, rows: page.data, nextCursor: page.nextCursor, stale: false });
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
                            setLoaded({ key: filterKey, rows: cached.map(fromCache), nextCursor: null, stale: true });
                            setFailed(cached.length === 0);
                        }
                    },
                    () => {
                        if (active) {
                            setLoaded({ key: filterKey, rows: [], nextCursor: null, stale: true });
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

    const loadMore = async (): Promise<void> => {
        if (current === null || current.nextCursor === null) {
            return;
        }

        setLoadingMore(true);

        try {
            const page = await listDocuments(subjectId, filters, current.nextCursor);

            setLoaded({
                key: filterKey,
                rows: [...current.rows, ...page.data],
                nextCursor: page.nextCursor,
                stale: false,
            });
        } catch {
            // Leave what is already on screen; the button stays available to try again.
        } finally {
            setLoadingMore(false);
        }
    };

    const resetFilters = (): void => {
        setStatus('');
        setType('');
        setReceivedFrom('');
        setReceivedTo('');
        setNeedsReview(false);
    };

    return (
        <AppShell>
            <Head title={t((d) => d.documentsInbox.title)} />
            <Page
                title={t((d) => d.documentsInbox.title)}
                lede={t((d) => d.documentsInbox.description)}
                actions={
                    <PanelButton
                        primary
                        inline
                        onClick={() => {
                            setAdding(true);
                        }}
                    >
                        {t((d) => d.documentsInbox.newDocument)}
                    </PanelButton>
                }
            >
                <Panel
                    title={t((d) => d.documentsInbox.title)}
                    aside={
                        current?.stale === true ? (
                            <Status tone="warn">{t((d) => d.documentsInbox.offline)}</Status>
                        ) : null
                    }
                    footNote={t((d) => d.documentsInbox.liveNotice)}
                    footActions={
                        current !== null && current.nextCursor !== null ? (
                            <PanelButton
                                disabled={loadingMore}
                                onClick={() => {
                                    void loadMore();
                                }}
                            >
                                {t((d) => (loadingMore ? d.common.loadingMore : d.common.loadMore))}
                            </PanelButton>
                        ) : undefined
                    }
                    flush
                >
                    <Toolbar>
                        <PanelSelect
                            className="filter-select"
                            value={status}
                            onValueChange={(value) => {
                                setStatus(value as DocumentStatus | '');
                            }}
                            placeholder={t((d) => d.documentsInbox.filters.allStatuses)}
                            emptyLabel={t((d) => d.documentsInbox.filters.allStatuses)}
                            options={DOCUMENT_STATUSES.map((value) => ({
                                value,
                                label: t((d) => d.statusBadge[value]),
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
                            options={DOCUMENT_TYPES.map((value) => ({
                                value,
                                label: t((d) => d.documentType[value]),
                            }))}
                        />
                        <PanelInput
                            className="filter-select"
                            type="date"
                            aria-label={t((d) => d.documentsInbox.filters.from)}
                            value={receivedFrom}
                            onChange={(event) => {
                                setReceivedFrom(event.target.value);
                            }}
                        />
                        <PanelInput
                            className="filter-select"
                            type="date"
                            aria-label={t((d) => d.documentsInbox.filters.to)}
                            value={receivedTo}
                            onChange={(event) => {
                                setReceivedTo(event.target.value);
                            }}
                        />
                        <label className="flex items-center gap-2 text-xs">
                            <Switch
                                checked={needsReview}
                                onCheckedChange={setNeedsReview}
                                aria-label={t((d) => d.documentsInbox.filters.needsReview)}
                            />
                            {t((d) => d.documentsInbox.filters.needsReview)}
                        </label>
                        <PanelButton onClick={resetFilters}>{t((d) => d.documentsInbox.filters.reset)}</PanelButton>
                    </Toolbar>

                    {documents === null ? (
                        <TableSkeleton />
                    ) : failed ? (
                        <p className="note p-4">{t((d) => d.documentsInbox.loadFailed)}</p>
                    ) : documents.length === 0 ? (
                        <p className="note p-4">{t((d) => d.documentsInbox.empty)}</p>
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
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
                                    <tr key={document.id}>
                                        <td className="col-primary">
                                            <div className="font-semibold break-all">
                                                {document.filename === ''
                                                    ? t((d) => d.documentType[document.type])
                                                    : document.filename}
                                            </div>
                                            {reason === null ? null : (
                                                <div className="text-destructive text-xs">
                                                    {t((d) => d.rejectionReason[reason])}
                                                </div>
                                            )}
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
                                            <Status tone={statusTone(document.status)}>
                                                {t((d) => d.statusBadge[document.status])}
                                            </Status>
                                            {document.pendingExtractions > 0 ? (
                                                <div className="note">
                                                    {t((d) => d.documentsInbox.pendingExtractions).replace(
                                                        '{count}',
                                                        String(document.pendingExtractions),
                                                    )}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td className="col-end text-right">
                                            <div className="flex justify-end gap-2">
                                                {document.status === 'READY' ? (
                                                    <Link
                                                        href={`/documents/${document.id}/review`}
                                                        className="ctl-action-link"
                                                    >
                                                        {t((d) => d.documentsInbox.review)}
                                                    </Link>
                                                ) : null}
                                                <PanelButton
                                                    variant="ghost"
                                                    onClick={() => {
                                                        setDeleting(document);
                                                    }}
                                                >
                                                    {t((d) => d.common.delete)}
                                                </PanelButton>
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
function fromCache(document: KnownDocument): DocumentListItem {
    return {
        id: document.id,
        filename: '',
        type: document.type,
        status: document.status,
        rejectionReason: document.rejectionReason,
        mimeType: document.mimeType,
        sizeBytes: document.sizeBytes,
        source: 'USER_UPLOAD',
        receivedAt: document.receivedAt,
        pendingExtractions: 0,
    };
}

/** Where a document sits in the pipeline, as a colour. Rejected is bad; everything else is progress. */
function statusTone(status: DocumentStatus): StatusTone {
    if (status === 'REJECTED') {
        return 'bad';
    }

    if (status === 'READY') {
        return 'ok';
    }

    return 'idle';
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
