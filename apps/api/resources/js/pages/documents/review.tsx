import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    CalendarDays,
    FileType2,
    FileUp,
    Hash,
    MoreHorizontal,
    Pencil,
    Plus,
    ScrollText,
    Tag,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type JSX, type ReactNode, type SyntheticEvent } from 'react';
import AppShell from '../../components/AppShell';
import CreateTransactionDialog from '../../components/documents/CreateTransactionDialog';
import ErrorText from '../../components/ErrorText';
import {
    DocumentContentNotAvailableError,
    ExtractionAlreadyReviewedError,
    acceptDocumentExtraction,
    correctDocumentExtraction,
    fetchDocument,
    fetchDocumentContent,
    fetchDocumentExtractions,
    fetchDocumentHistory,
    renameDocument,
    retryDocumentExtraction,
} from '../../api/documentsApi';
import { useCoalescedCallback } from '../../hooks/useCoalescedCallback';
import { useDocumentsChannel } from '../../hooks/useDocumentsChannel';
import { useTranslation } from '../../i18n';
import { suggestDocumentName } from '../../lib/documentName';
import { cn } from '@/lib/utils';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '../../components/ui/dropdown-menu';
import { formatDateTime } from '../../lib/format';
import { ApiError } from '../../lib/http';
import { getActiveSubjectId } from '../../lib/subjectStore';
import type {
    DocumentExtractionSummary,
    DocumentHistory,
    DocumentLifecycleEvent,
    DocumentSummary,
} from '../../types/documents';
import {
    Detail,
    DetailList,
    Page,
    ActionGroup,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelSelect,
    Pill,
    Status,
    type StatusTone,
} from '../../components/panel';
import { Button } from '@/components/ui/button';

/** Statuses for which `GET .../documents/{document}/content` can return bytes
 * (`App\Http\Controllers\Api\V1\Documents\ShowDocumentContentController::CONTENT_AVAILABLE_STATUSES`). */
const CONTENT_AVAILABLE_STATUSES: DocumentSummary['status'][] = ['SCANNED', 'PROCESSING', 'READY'];

/**
 * Where an extraction stands, as a tone.
 *
 * `PROPOSED` is deliberately `warn` rather than a neutral colour: it is the one state that is
 * waiting on a person, and the screen exists to make that visible. Nothing here is ever
 * auto-confirmed (`BLUEPRINT.md` §14.6).
 */
function extractionTone(status: DocumentExtractionSummary['status']): StatusTone {
    switch (status) {
        case 'PROPOSED':
            return 'warn';
        case 'ACCEPTED':
            return 'ok';
        case 'REJECTED':
            return 'bad';
        default:
            return 'idle';
    }
}

/** Where a document sits in the pipeline. Rejected is a fault; everything else is progress. */
/**
 * One labelled fact in the summary band: a small uppercase caption over its value.
 *
 * Not a `DetailList` row — that stacks label and value down a column, and this band reads across
 * four of them, so the caption sits above rather than beside.
 */
function SummaryFact({ icon, label, children }: { icon: ReactNode; label: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <div className="fact-label">
                {icon}
                {label}
            </div>
            <div className="fact-value">{children}</div>
        </div>
    );
}

/**
 * A status tone as a text colour, for the dot-and-label form the tables use.
 *
 * The same treatment as the inbox: the dot carries the colour and the label stays text, so the two
 * screens describe a document's state identically.
 */
function statusToneClass(tone: StatusTone): string {
    return {
        ok: 'text-success',
        warn: 'text-warning',
        bad: 'text-destructive',
        idle: 'text-muted-foreground',
    }[tone];
}

function documentTone(status: DocumentSummary['status']): StatusTone {
    if (status === 'REJECTED') {
        return 'bad';
    }

    return status === 'READY' ? 'ok' : 'idle';
}

/**
 * How sure the extractor was, as a bar and a percentage.
 *
 * A `role="meter"` rather than a bare div: the number is the point, and a screen reader has to be
 * able to read it. The three bands are a reviewer's cue about where to look first, not a threshold
 * anything is decided on — nothing is ever auto-confirmed on confidence alone.
 */
function ConfidenceBar({ confidence }: { confidence: number }): JSX.Element {
    const { t } = useTranslation();
    const percent = Math.round(confidence * 100);
    // `--attention` is the palette's own "a person should look at this" colour, so a middling
    // confidence is marked in the same hue as everything else asking for attention.
    const tone = confidence >= 0.85 ? 'bg-success' : confidence >= 0.6 ? 'bg-[var(--attention)]' : 'bg-destructive';

    return (
        <div
            className="flex flex-1 items-center gap-2"
            role="meter"
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={t((d) => d.documentsReview.confidenceLabel)}
        >
            <div className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                <div className={`h-full rounded-full ${tone}`} style={{ width: `${String(percent)}%` }} />
            </div>
        </div>
    );
}

/**
 * Renders any JSON value as inert text — never HTML, never interpreted. `proposed_fields` values
 * originate from an AI proposal or a human transcription of a document's contents, both untrusted
 * (`BLUEPRINT.md` §14.5); this only ever produces a plain string handed to JSX text content (React
 * escapes it), never `dangerouslySetInnerHTML`.
 */
function fieldValueAsText(value: unknown, unrenderable: string): string {
    if (typeof value === 'string') {
        return value;
    }

    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    try {
        return JSON.stringify(value);
    } catch {
        // A circular structure, or a value whose `toJSON` throws. The caller supplies the copy —
        // no user-facing string is written in code (BLUEPRINT.md §19.1).
        return unrenderable;
    }
}

interface CorrectionRow {
    key: string;
    value: string;
}

function fieldsToRows(fields: Record<string, unknown>, unrenderable: string): CorrectionRow[] {
    const entries = Object.entries(fields);

    return entries.length > 0
        ? entries.map(([key, value]) => ({ key, value: fieldValueAsText(value, unrenderable) }))
        : [{ key: '', value: '' }];
}

export default function DocumentReview({ documentId }: { documentId: string }): JSX.Element {
    const { t, locale } = useTranslation();
    const unrenderableValue = t((d) => d.documentsReview.unrenderableValue);
    const [subjectId] = useState(() => getActiveSubjectId());
    const [document, setDocument] = useState<DocumentSummary | null>(null);
    const [documentError, setDocumentError] = useState<string | null>(null);

    const [extractions, setExtractions] = useState<DocumentExtractionSummary[]>([]);
    const [extractionsCursor, setExtractionsCursor] = useState<string | null>(null);
    const [extractionsError, setExtractionsError] = useState<string | null>(null);
    const [extractionsLoading, setExtractionsLoading] = useState(true);

    const [retrying, setRetrying] = useState(false);
    const [retryNotice, setRetryNotice] = useState<string | null>(null);
    const [retryError, setRetryError] = useState<string | null>(null);

    /**
     * A reading is running right now.
     *
     * `PROCESSING` is claimed by the retry endpoint before it dispatches, so this is true from the
     * moment the request is accepted rather than from whenever a worker picks the job up — the gap
     * in between is exactly where repeated clicks used to slip through.
     */
    const isReading = document?.status === 'PROCESSING';

    /**
     * The fields a correction may name, required ones first.
     *
     * From the server, which reads `ExtractionSchema` — the one place that decides what a receipt
     * or an invoice is made of. A document type with no schema yet offers nothing, which is the
     * honest answer rather than a free-text box that accepts anything.
     */
    const correctableFields = [
        ...(document?.extractionFields.required ?? []).map((name) => ({ name, required: true })),
        ...(document?.extractionFields.optional ?? []).map((name) => ({ name, required: false })),
    ];

    /** The newest reading's confidence, for the panel head. Null while nothing has been proposed. */
    const newestConfidence = extractions.length === 0 ? null : Number(extractions[0]?.confidence ?? 0);

    /**
     * A name built from whatever the newest proposal read out of this document.
     *
     * Null whenever nothing identifying was extracted — a suggestion of "bon-fiscal.png" for every
     * receipt distinguishes no better than the hash it would replace.
     */
    const suggestedName =
        document === null
            ? null
            : suggestDocumentName(
                  t((d) => d.documentType[document.type]),
                  extractions[0]?.proposedFields ?? {},
                  document.filename,
              );

    const [renaming, setRenaming] = useState(false);
    const [showingChain, setShowingChain] = useState(false);
    const [nameDraft, setNameDraft] = useState('');
    const [savingName, setSavingName] = useState(false);
    const [renameError, setRenameError] = useState<string | null>(null);

    const [busyExtractionId, setBusyExtractionId] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    const [correctingId, setCorrectingId] = useState<string | null>(null);
    const [correctionRows, setCorrectionRows] = useState<CorrectionRow[]>([]);

    const [history, setHistory] = useState<DocumentHistory | null>(null);
    const [creatingTransaction, setCreatingTransaction] = useState(false);

    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [previewMime, setPreviewMime] = useState<string | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const previewUrlRef = useRef<string | null>(null);

    const loadDocument = useCallback(async () => {
        if (!subjectId) {
            return;
        }

        try {
            setDocument(await fetchDocument(subjectId, documentId));
            setDocumentError(null);
        } catch (cause) {
            if (cause instanceof ApiError && cause.status === 401) {
                window.location.assign('/login');

                return;
            }

            setDocumentError(cause instanceof ApiError ? cause.message : t((d) => d.documentsReview.title));
        }
    }, [subjectId, documentId, t]);

    const loadExtractions = useCallback(async () => {
        if (!subjectId) {
            return;
        }

        setExtractionsLoading(true);

        try {
            const page = await fetchDocumentExtractions(subjectId, documentId);
            setExtractions(page.data);
            setExtractionsCursor(page.nextCursor);
            setExtractionsError(null);
        } catch (cause) {
            setExtractionsError(cause instanceof ApiError ? cause.message : t((d) => d.documentsReview.loading));
        } finally {
            setExtractionsLoading(false);
        }
    }, [subjectId, documentId, t]);

    async function loadMoreExtractions(): Promise<void> {
        if (!subjectId || !extractionsCursor) {
            return;
        }

        try {
            const page = await fetchDocumentExtractions(subjectId, documentId, extractionsCursor);
            setExtractions((current) => [...current, ...page.data]);
            setExtractionsCursor(page.nextCursor);
        } catch (cause) {
            setExtractionsError(cause instanceof ApiError ? cause.message : t((d) => d.documentsReview.loading));
        }
    }

    const loadHistory = useCallback(async () => {
        if (!subjectId) {
            return;
        }

        try {
            setHistory(await fetchDocumentHistory(subjectId, documentId));
        } catch {
            // A missing history is not worth breaking the screen over — the panel says so itself.
            setHistory({ scans: [], events: [] });
        }
    }, [subjectId, documentId]);

    useEffect(() => {
        void (async () => {
            await loadDocument();
            await loadExtractions();
            await loadHistory();
        })();
    }, [loadDocument, loadExtractions, loadHistory]);

    // Preview: only attempted once the original bytes exist server-side, and revoked on every
    // change so a decrypted document is never held in memory longer than this screen is open
    // (`BLUEPRINT.md` §17 — decrypted documents are never persisted client-side; an object URL is
    // memory-only and dies with the tab).
    useEffect(() => {
        if (previewUrlRef.current) {
            URL.revokeObjectURL(previewUrlRef.current);
            previewUrlRef.current = null;
        }

        const cancelledRef = { current: false };

        void (async () => {
            setPreviewUrl(null);
            setPreviewMime(null);
            setPreviewError(null);

            if (!subjectId || !document || !CONTENT_AVAILABLE_STATUSES.includes(document.status)) {
                return;
            }

            setPreviewLoading(true);

            try {
                const blob = await fetchDocumentContent(subjectId, documentId);

                if (cancelledRef.current) {
                    return;
                }

                const url = URL.createObjectURL(blob);
                previewUrlRef.current = url;
                setPreviewUrl(url);
                setPreviewMime(blob.type || document.mimeType);
            } catch (cause) {
                if (cancelledRef.current) {
                    return;
                }

                setPreviewError(
                    cause instanceof DocumentContentNotAvailableError
                        ? t((d) => d.documentsReview.rejectedNotice)
                        : cause instanceof ApiError
                          ? cause.message
                          : t((d) => d.documentsReview.loadingPreview),
                );
            } finally {
                if (!cancelledRef.current) {
                    setPreviewLoading(false);
                }
            }
        })();

        return () => {
            cancelledRef.current = true;
        };
    }, [document, subjectId, documentId, t]);

    useEffect(
        () => () => {
            if (previewUrlRef.current) {
                URL.revokeObjectURL(previewUrlRef.current);
            }
        },
        [],
    );

    // Three endpoints per event was three requests per event, and a document walking the pipeline
    // emits four or five of them within a second or two — so opening this page during processing
    // fired a dozen requests to end up exactly where one round would have.
    const refetch = useCoalescedCallback(() => {
        void loadDocument();
        void loadExtractions();
        void loadHistory();
    });

    const handleEvent = useCallback(
        (event: DocumentLifecycleEvent) => {
            if (event.documentId !== documentId) {
                return;
            }

            refetch();
        },
        [documentId, refetch],
    );

    useDocumentsChannel(subjectId, handleEvent);

    /**
     * Ask the server to read the document again.
     *
     * Nothing is awaited beyond the 202: the reading runs on the queue and the result arrives over
     * the documents channel, which already reloads this page on any event for this document.
     */
    async function handleRetryExtraction(): Promise<void> {
        if (!subjectId) {
            return;
        }

        setRetrying(true);
        setRetryError(null);
        setRetryNotice(null);

        try {
            await retryDocumentExtraction(subjectId, documentId);
            setRetryNotice(t((d) => d.documentsReview.aiOutcomeRetryQueued));
            // The server has already moved the document to PROCESSING; show that immediately rather
            // than waiting for the first broadcast to arrive.
            await loadDocument();
        } catch (cause) {
            setRetryError(cause instanceof ApiError ? cause.message : t((d) => d.documentsReview.aiOutcomeRetryFailed));
        } finally {
            setRetrying(false);
        }
    }

    async function handleRename(next: string | null): Promise<void> {
        if (!subjectId) {
            return;
        }

        setSavingName(true);
        setRenameError(null);

        try {
            await renameDocument(subjectId, documentId, next === '' ? null : next);
            setRenaming(false);
            await loadDocument();
        } catch (cause) {
            setRenameError(cause instanceof ApiError ? cause.message : t((d) => d.documentsReview.renameFailed));
        } finally {
            setSavingName(false);
        }
    }

    async function handleAccept(extraction: DocumentExtractionSummary): Promise<void> {
        if (!subjectId) {
            return;
        }

        setActionError(null);
        setBusyExtractionId(extraction.id);

        try {
            await acceptDocumentExtraction(subjectId, documentId, extraction.id);
            await loadExtractions();
        } catch (cause) {
            setActionError(
                cause instanceof ExtractionAlreadyReviewedError
                    ? t((d) => d.documentsReview.alreadyReviewedError)
                    : cause instanceof ApiError
                      ? cause.message
                      : t((d) => d.documentsReview.acceptError),
            );
            await loadExtractions();
        } finally {
            setBusyExtractionId(null);
        }
    }

    function startCorrection(extraction: DocumentExtractionSummary): void {
        setActionError(null);
        setCorrectingId(extraction.id);
        setCorrectionRows(fieldsToRows(extraction.proposedFields, unrenderableValue));
    }

    function cancelCorrection(): void {
        setCorrectingId(null);
        setCorrectionRows([]);
    }

    function updateCorrectionRow(index: number, patch: Partial<CorrectionRow>): void {
        setCorrectionRows((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function addCorrectionRow(): void {
        setCorrectionRows((rows) => [...rows, { key: '', value: '' }]);
    }

    function removeCorrectionRow(index: number): void {
        setCorrectionRows((rows) => rows.filter((_, i) => i !== index));
    }

    async function submitCorrection(event: SyntheticEvent<HTMLFormElement>, extractionId: string): Promise<void> {
        event.preventDefault();

        if (!subjectId) {
            return;
        }

        const fields: Record<string, unknown> = {};

        for (const row of correctionRows) {
            const key = row.key.trim();

            if (key !== '') {
                fields[key] = row.value;
            }
        }

        if (Object.keys(fields).length === 0) {
            setActionError(t((d) => d.documentsReview.emptyCorrectionError));

            return;
        }

        setActionError(null);
        setBusyExtractionId(extractionId);

        try {
            await correctDocumentExtraction(subjectId, documentId, extractionId, fields);
            cancelCorrection();
            await loadExtractions();
        } catch (cause) {
            setActionError(
                cause instanceof ExtractionAlreadyReviewedError
                    ? t((d) => d.documentsReview.alreadyReviewedError)
                    : cause instanceof ApiError
                      ? cause.message
                      : t((d) => d.documentsReview.correctError),
            );
            await loadExtractions();
        } finally {
            setBusyExtractionId(null);
        }
    }

    if (!subjectId) {
        return (
            <AppShell>
                <Head title={t((d) => d.documentsReview.title)} />
                <ErrorText message={t((d) => d.documentsReview.noActiveSubject)} />
            </AppShell>
        );
    }

    const rejection = document?.rejectionReason ?? null;

    // An accepted reading is what a person already agreed to, so it wins; otherwise the newest
    // proposal is the best starting point. Null when nothing could read this document, which is not
    // a blocker — the form simply starts empty and the person types what they see.
    const bestReading =
        extractions.find((candidate) => candidate.status === 'ACCEPTED') ??
        extractions.find((candidate) => candidate.status === 'PROPOSED') ??
        null;

    return (
        <AppShell>
            <Head title={t((d) => d.documentsReview.title)} />
            <Page
                // The topbar says what this screen is; the document names itself below, next to the
                // control that goes back to the list it came from.
                title={t((d) => d.documentsReview.title)}
                lede={t((d) => d.documentsReview.description)}
            >
                <ErrorText message={documentError} />

                {/* The document's header and its facts in one card: the way back, what it is
                    called, what can be done to it, and everything known about it. Two stacked
                    headers — a page row above a card head repeating it — said the same thing twice. */}
                {document !== null && (
                    <Panel
                        icon={
                            <Link
                                href="/documents"
                                className="grid size-full place-items-center"
                                aria-label={t((d) => d.documentsReview.back)}
                            >
                                <ArrowLeft className="size-4" aria-hidden="true" />
                            </Link>
                        }
                        title={document.filename}
                        aside={
                            <>
                                {/* Three labelled actions need room the card head does not have on a
                                    phone. Same three, same order, behind one control — not a
                                    different set, which would make the two screens disagree about
                                    what you can do to a document. */}
                                {/* The toggle sits on a wrapper, not on the button: `.shell-control`
                                    is unlayered CSS, so its `display` beats `sm:hidden` and the
                                    control would show at every width. */}
                                <div className="sm:hidden">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button
                                                type="button"
                                                className="shell-control size-8 justify-center px-0"
                                                aria-label={t((d) => d.documentsReview.actions)}
                                            >
                                                <MoreHorizontal className="size-4" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" className="w-52">
                                            <DropdownMenuItem
                                                onSelect={() => {
                                                    setNameDraft(document.filename);
                                                    setRenameError(null);
                                                    setRenaming(true);
                                                }}
                                            >
                                                <Pencil className="size-4" />
                                                {t((d) => d.documentsReview.rename)}
                                            </DropdownMenuItem>
                                            {document.status !== 'REJECTED' && (
                                                <DropdownMenuItem
                                                    onSelect={() => {
                                                        setCreatingTransaction(true);
                                                    }}
                                                >
                                                    <Plus className="size-4" />
                                                    {t((d) => d.documentsReview.createTransaction)}
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuItem
                                                onSelect={() => {
                                                    setShowingChain(true);
                                                }}
                                            >
                                                <ScrollText className="size-4" />
                                                {t((d) => d.documentsReview.chainTitle)}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>

                                <div className="hidden sm:block">
                                    <ActionGroup
                                        actions={[
                                            {
                                                key: 'rename',
                                                label: t((d) => d.documentsReview.rename),
                                                icon: <Pencil className="size-3.5" aria-hidden="true" />,
                                                onClick: () => {
                                                    setNameDraft(document.filename);
                                                    setRenameError(null);
                                                    setRenaming(true);
                                                },
                                            },
                                            ...(document.status === 'REJECTED'
                                                ? []
                                                : [
                                                      {
                                                          key: 'transaction',
                                                          label: t((d) => d.documentsReview.createTransaction),
                                                          icon: <Plus className="size-3.5" aria-hidden="true" />,
                                                          tone: 'primary' as const,
                                                          onClick: () => {
                                                              setCreatingTransaction(true);
                                                          },
                                                      },
                                                  ]),
                                            {
                                                key: 'chain',
                                                label: t((d) => d.documentsReview.chainTitle),
                                                icon: <ScrollText className="size-3.5" aria-hidden="true" />,
                                                onClick: () => {
                                                    setShowingChain(true);
                                                },
                                            },
                                        ]}
                                    />
                                </div>
                            </>
                        }
                    >
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                            <div className="grid grid-cols-2 gap-x-8 gap-y-3 text-xs lg:grid-cols-3">
                                <SummaryFact
                                    icon={<FileUp className="size-3.5" />}
                                    label={t((d) => d.documentsReview.originalFilenameLabel)}
                                >
                                    <span className="break-all">{document.originalFilename}</span>
                                </SummaryFact>
                                <SummaryFact
                                    icon={<Tag className="size-3.5" />}
                                    label={t((d) => d.documentsReview.typeLabel)}
                                >
                                    {t((d) => d.documentType[document.type])}
                                </SummaryFact>
                                <SummaryFact
                                    icon={<FileType2 className="size-3.5" />}
                                    label={t((d) => d.documentsReview.formatLabel)}
                                >
                                    {document.mimeType}
                                </SummaryFact>
                                <SummaryFact
                                    icon={<CalendarDays className="size-3.5" />}
                                    label={t((d) => d.documentsReview.receivedLabel)}
                                >
                                    <time dateTime={document.receivedAt} className="tabular-nums">
                                        {formatDateTime(document.receivedAt, locale)}
                                    </time>
                                </SummaryFact>
                                <SummaryFact
                                    icon={<Hash className="size-3.5" />}
                                    label={t((d) => d.documentsReview.documentIdLabel)}
                                >
                                    {/* The leading segment only: a UUIDv7's first block is the timestamp
                                    and is what makes one recognisable without printing 36 characters. */}
                                    <code className="fact-id">{document.id.split('-')[0]}</code>
                                </SummaryFact>
                                <SummaryFact
                                    icon={<Activity className="size-3.5" />}
                                    label={t((d) => d.documentsReview.statusLabel)}
                                >
                                    <span className={cn('status-dot', statusToneClass(documentTone(document.status)))}>
                                        {t((d) => d.documentStage[document.stage])}
                                    </span>
                                </SummaryFact>
                            </div>

                            {/* What the security check found, beside the facts rather than in a card of
                            its own at the foot: it is a fact about the file like the six on the
                            left, not a separate subject. */}
                            {history !== null && (
                                <div className="border-border lg:border-l lg:pl-4">
                                    <div className="eyebrow mb-2">{t((d) => d.documentsReview.scansTitle)}</div>
                                    {history.scans.length === 0 ? (
                                        // Never "clean by default": a document with no scan row was not
                                        // scanned, and saying so is the point of keeping the rows at all.
                                        <p className="note">{t((d) => d.documentsReview.noScans)}</p>
                                    ) : (
                                        <ul className="space-y-2">
                                            {history.scans.map((scan) => (
                                                <li key={scan.id}>
                                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                                        <Status tone={scan.outcome === 'INFECTED' ? 'bad' : 'ok'}>
                                                            {t(
                                                                (d) =>
                                                                    d.documentsReview.scanOutcomes[scan.outcome] ??
                                                                    scan.outcome,
                                                            )}
                                                        </Status>
                                                        <span className="note tabular-nums">
                                                            {formatDateTime(scan.scannedAt, locale)}
                                                        </span>
                                                    </div>
                                                    <DetailList>
                                                        <Detail label={t((d) => d.documentsReview.scannerLabel)}>
                                                            {scan.scanner}
                                                            {scan.scannerVersion === null
                                                                ? ''
                                                                : ` ${scan.scannerVersion}`}
                                                        </Detail>
                                                        <Detail label={t((d) => d.documentsReview.definitionsLabel)}>
                                                            {scan.definitionsVersion ??
                                                                t((d) => d.documentsReview.notRecorded)}
                                                        </Detail>
                                                    </DetailList>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}
                        </div>

                        {rejection !== null && (
                            <p className="text-destructive mt-3 text-xs">{t((d) => d.rejectionReason[rejection])}</p>
                        )}
                    </Panel>
                )}

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.08fr)_minmax(380px,0.92fr)]">
                    <Panel title={t((d) => d.documentsReview.source)}>
                        <>
                            {document && !CONTENT_AVAILABLE_STATUSES.includes(document.status) && (
                                <div className="flex h-64 items-center justify-center rounded-md border border-dashed border-border bg-muted p-4 text-center text-sm text-muted-foreground">
                                    {document.status === 'QUARANTINE'
                                        ? t((d) => d.documentsReview.quarantineNotice)
                                        : t((d) => d.documentsReview.rejectedNotice)}
                                </div>
                            )}

                            {document && CONTENT_AVAILABLE_STATUSES.includes(document.status) && (
                                <>
                                    {previewLoading && (
                                        <p className="text-sm text-muted-foreground">
                                            {t((d) => d.documentsReview.loadingPreview)}
                                        </p>
                                    )}
                                    <ErrorText message={previewError} />
                                    {previewUrl && previewMime?.startsWith('image/') && (
                                        <img
                                            src={previewUrl}
                                            alt={t((d) => d.documentsReview.previewAlt)}
                                            className="doc-preview-image"
                                        />
                                    )}
                                    {previewUrl && previewMime === 'application/pdf' && (
                                        <iframe
                                            src={previewUrl}
                                            title={t((d) => d.documentsReview.previewPdfTitle)}
                                            className="doc-preview"
                                        />
                                    )}
                                    {previewUrl &&
                                        !previewMime?.startsWith('image/') &&
                                        previewMime !== 'application/pdf' && (
                                            <div className="rounded-md border border-dashed border-border bg-muted p-4 text-center text-sm text-muted-foreground">
                                                <p className="mb-2">{t((d) => d.documentsReview.unsupportedPreview)}</p>
                                                <Button variant="link" className="h-auto p-0" asChild>
                                                    <a href={previewUrl} target="_blank" rel="noopener noreferrer">
                                                        {t((d) => d.documentsReview.openInNewTab)}
                                                    </a>
                                                </Button>
                                            </div>
                                        )}
                                </>
                            )}
                        </>
                    </Panel>

                    <Panel
                        title={t((d) => d.documentsReview.proposedExtractions)}
                        description={t((d) => d.documentsReview.proposedExtractionsHint)}
                        aside={
                            newestConfidence === null ? undefined : (
                                <span className="head-chip">
                                    {t((d) => d.documentsReview.confidenceChip).replace(
                                        '{percent}',
                                        String(Math.round(newestConfidence * 100)),
                                    )}
                                </span>
                            )
                        }
                        footNote={t((d) => d.documentsReview.noAutoConfirmNotice)}
                        footActions={
                            extractionsCursor === null ? undefined : (
                                <PanelButton
                                    onClick={() => {
                                        void loadMoreExtractions();
                                    }}
                                >
                                    {t((d) => d.documentsReview.loadMore)}
                                </PanelButton>
                            )
                        }
                    >
                        <div className="space-y-3">
                            <ErrorText message={extractionsError} />
                            <ErrorText message={actionError} />

                            {extractionsLoading && (
                                <p className="text-sm text-muted-foreground">{t((d) => d.documentsReview.loading)}</p>
                            )}

                            {/* A reading is in flight. Shown ahead of any recorded outcome, because
                                that outcome belongs to the previous attempt and saying "it failed"
                                while the next one is running is simply wrong. */}
                            {isReading && (
                                <div className="rounded-md border border-border bg-muted px-4 py-3">
                                    <Status tone="warn">{t((d) => d.documentsReview.aiOutcomeWorking)}</Status>
                                    <p className="note mt-2">{t((d) => d.documentsReview.aiOutcomeWorkingHint)}</p>
                                </div>
                            )}

                            {/* An attempt that ended without a proposal used to look exactly like one
                                that had never been made. The outcome is a closed enum, so the copy for
                                each case lives in the translation files, not here. */}
                            {!isReading &&
                                !extractionsLoading &&
                                document?.aiExtractionOutcome != null &&
                                document.aiExtractionOutcome !== 'SUCCEEDED' && (
                                    <div className="rounded-md border border-border bg-muted px-4 py-3">
                                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <Status
                                                tone={
                                                    document.aiExtractionOutcome.startsWith('FAILED_') ? 'bad' : 'warn'
                                                }
                                            >
                                                {t((d) => d.documentsReview.aiOutcomeTitle)}
                                            </Status>
                                            {document.aiExtractionAttemptedAt !== null && (
                                                <span className="note tabular-nums">
                                                    {t((d) => d.documentsReview.aiOutcomeAttemptedAt)}:{' '}
                                                    {formatDateTime(document.aiExtractionAttemptedAt, locale)}
                                                </span>
                                            )}
                                        </div>

                                        <p className="text-sm text-muted-foreground">
                                            {t((d) => d.documentsReview.aiOutcomes)[document.aiExtractionOutcome]}
                                        </p>

                                        {extractions.length === 0 && (
                                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={retrying || isReading}
                                                    onClick={() => {
                                                        void handleRetryExtraction();
                                                    }}
                                                >
                                                    {retrying
                                                        ? t((d) => d.documentsReview.aiOutcomeRetrying)
                                                        : t((d) => d.documentsReview.aiOutcomeRetry)}
                                                </Button>
                                                <span className="note">
                                                    {t((d) => d.documentsReview.aiOutcomeRetryHint)}
                                                </span>
                                            </div>
                                        )}

                                        {retryNotice !== null && <p className="note mt-2">{retryNotice}</p>}
                                        <ErrorText message={retryError} />
                                    </div>
                                )}

                            {/* Genuinely nothing has been tried yet — the only case where "yet" is true. */}
                            {!isReading &&
                                !extractionsLoading &&
                                extractions.length === 0 &&
                                (document?.aiExtractionOutcome == null ||
                                    document.aiExtractionOutcome === 'SUCCEEDED') && (
                                    <p className="rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                        {t((d) => d.documentsReview.empty)}
                                    </p>
                                )}

                            <ul className="space-y-3">
                                {extractions.map((extraction) => (
                                    <li
                                        key={extraction.id}
                                        className="border-border border-t pt-3 first:border-t-0 first:pt-0"
                                    >
                                        <div className="mb-2 flex flex-wrap items-center gap-2">
                                            <Pill>{t((d) => d.extractorType[extraction.extractorType])}</Pill>
                                            <span className="note tabular-nums">
                                                {formatDateTime(extraction.createdAt, locale)}
                                            </span>
                                        </div>

                                        {/* The reading's standing and how sure it is, on one line —
                                            the bar takes whatever the label leaves, as drawn. */}
                                        <div className="mb-3 flex items-center gap-2">
                                            <Status tone={extractionTone(extraction.status)}>
                                                {t((d) => d.extractionStatus[extraction.status])}
                                            </Status>
                                            <ConfidenceBar confidence={Number(extraction.confidence)} />
                                        </div>

                                        <DetailList>
                                            {Object.entries(extraction.proposedFields).map(([key, value]) => (
                                                <Detail key={key} label={key}>
                                                    <span className="tabular-nums break-words">
                                                        {fieldValueAsText(value, unrenderableValue)}
                                                    </span>
                                                </Detail>
                                            ))}
                                        </DetailList>

                                        {extraction.reviewedBy === null ? null : (
                                            <p className="note mt-2">
                                                {t((d) => d.documentsReview.reviewedBy)} {extraction.reviewedBy} —{' '}
                                                {formatDateTime(extraction.updatedAt, locale)}
                                            </p>
                                        )}

                                        {extraction.status === 'PROPOSED' && correctingId !== extraction.id && (
                                            <div className="mt-2 flex gap-2">
                                                <PanelButton
                                                    primary
                                                    disabled={busyExtractionId === extraction.id}
                                                    onClick={() => {
                                                        void handleAccept(extraction);
                                                    }}
                                                >
                                                    {t((d) => d.documentsReview.accept)}
                                                </PanelButton>
                                                <PanelButton
                                                    disabled={busyExtractionId === extraction.id}
                                                    onClick={() => {
                                                        startCorrection(extraction);
                                                    }}
                                                >
                                                    {t((d) => d.documentsReview.correct)}
                                                </PanelButton>
                                            </div>
                                        )}

                                        {correctingId === extraction.id && (
                                            <form
                                                onSubmit={(event) => void submitCorrection(event, extraction.id)}
                                                className="tile border-border bg-card mt-3 space-y-2 border p-3"
                                            >
                                                <p className="note">{t((d) => d.documentsReview.correctionHint)}</p>

                                                {correctionRows.map((row, index) => (
                                                    <div key={index} className="field-row">
                                                        {/* The field names this document type
                                                            actually has, not free text: a typed
                                                            "amout" is accepted, stored, and useless
                                                            to everything downstream. */}
                                                        <PanelSelect
                                                            aria-label={t(
                                                                (d) => d.documentsReview.fieldNamePlaceholder,
                                                            )}
                                                            placeholder={t(
                                                                (d) => d.documentsReview.fieldNamePlaceholder,
                                                            )}
                                                            value={row.key}
                                                            onValueChange={(value) => {
                                                                updateCorrectionRow(index, { key: value });
                                                            }}
                                                            options={correctableFields.map((field) => ({
                                                                value: field.name,
                                                                // The wire name if nobody has
                                                                // translated it yet — better a
                                                                // reviewer sees `issuer_tax_id`
                                                                // than an empty option.
                                                                label:
                                                                    t((d) => d.extractionField)[field.name] ??
                                                                    field.name,
                                                                description: field.required
                                                                    ? t((d) => d.documentsReview.fieldRequired)
                                                                    : t((d) => d.documentsReview.fieldOptional),
                                                            }))}
                                                        />
                                                        <PanelInput
                                                            type="text"
                                                            aria-label={t(
                                                                (d) => d.documentsReview.fieldValuePlaceholder,
                                                            )}
                                                            placeholder={t(
                                                                (d) => d.documentsReview.fieldValuePlaceholder,
                                                            )}
                                                            value={row.value}
                                                            onChange={(event) => {
                                                                updateCorrectionRow(index, {
                                                                    value: event.target.value,
                                                                });
                                                            }}
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={t((d) => d.documentsReview.removeField)}
                                                            onClick={() => {
                                                                removeCorrectionRow(index);
                                                            }}
                                                        >
                                                            <Trash2 className="text-muted-foreground hover:text-destructive size-3.5" />
                                                        </Button>
                                                    </div>
                                                ))}

                                                {/* A dashed full-width row rather than a bare text
                                                    link: it sits at the end of a column of field
                                                    rows and should read as the next empty one,
                                                    which is what pressing it produces. */}
                                                <button
                                                    type="button"
                                                    className="add-row"
                                                    onClick={addCorrectionRow}
                                                    disabled={correctableFields.length === 0}
                                                >
                                                    <Plus className="size-3.5" aria-hidden="true" />
                                                    {t((d) => d.documentsReview.addField)}
                                                </button>

                                                <div className="flex gap-2 pt-1">
                                                    {/* A real submit button, not a PanelButton: this is
                                                    inside a <form>, and the form's onSubmit is what
                                                    handles Enter in either field. */}
                                                    <Button
                                                        type="submit"
                                                        className="ctl-action"
                                                        disabled={busyExtractionId === extraction.id}
                                                    >
                                                        {t((d) => d.documentsReview.submitCorrection)}
                                                    </Button>
                                                    <PanelButton onClick={cancelCorrection}>
                                                        {t((d) => d.documentsReview.cancelCorrection)}
                                                    </PanelButton>
                                                </div>
                                            </form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Panel>
                </div>
            </Page>

            {/* Rendered only while open so its account fetch does not run on every review, and
                re-reads the history on success: creating a transaction adds an audit entry that
                belongs on the timeline below. */}
            {creatingTransaction && document !== null ? (
                <CreateTransactionDialog
                    subjectId={subjectId}
                    document={document}
                    extraction={bestReading}
                    open={creatingTransaction}
                    onOpenChange={setCreatingTransaction}
                    onCreated={() => {
                        void loadHistory();
                    }}
                />
            ) : null}
            {/* The immutable record, on request. It is what you consult when something is
                disputed, not something the page needs to carry in front of you the rest of the time. */}
            <PanelDialog
                open={showingChain}
                onOpenChange={setShowingChain}
                size="lg"
                title={t((d) => d.documentsReview.chainTitle)}
                description={t((d) => d.documentsReview.historyHint)}
                actions={
                    <PanelButton
                        onClick={() => {
                            setShowingChain(false);
                        }}
                    >
                        {t((d) => d.common.close)}
                    </PanelButton>
                }
            >
                {history === null ? (
                    <p className="note">{t((d) => d.documentsReview.loading)}</p>
                ) : (
                    <div>
                        <div className="eyebrow mb-2">{t((d) => d.documentsReview.chainTitle)}</div>
                        {history.events.length === 0 ? (
                            <p className="note">{t((d) => d.documentsReview.noEvents)}</p>
                        ) : (
                            <ul className="space-y-1">
                                {history.events.map((event) => (
                                    <li
                                        key={`${event.eventType}-${String(event.sequence)}`}
                                        className="flex flex-wrap items-baseline justify-between gap-2 text-xs"
                                    >
                                        <span className="font-semibold">
                                            {t(
                                                (d) =>
                                                    d.documentsReview.chainEvents[event.eventType] ?? event.eventType,
                                            )}
                                        </span>
                                        <span className="note tabular-nums">
                                            #{event.sequence} · {formatDateTime(event.occurredAt, locale)} ·{' '}
                                            {/* The first bytes of the chain hash: enough to show the
                                                        entry is anchored, short enough to sit on one line. */}
                                            <span title={event.eventHash}>{event.eventHash.slice(0, 12)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </PanelDialog>

            {/* A modal rather than a panel that pushes the page around: renaming is one field and
                one decision, and the document behind it is the context you are naming from. */}
            <PanelDialog
                open={renaming}
                onOpenChange={setRenaming}
                title={t((d) => d.documentsReview.rename)}
                description={t((d) => d.documentsReview.nameHint)}
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                void handleRename(null);
                            }}
                        >
                            {t((d) => d.documentsReview.renameCleared)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={savingName}
                            onClick={() => {
                                void handleRename(nameDraft);
                            }}
                        >
                            {t((d) => d.documentsReview.renameSave)}
                        </PanelButton>
                    </>
                }
            >
                <div className="flex flex-col gap-2">
                    <PanelInput
                        icon={<Pencil className="size-3.5" />}
                        value={nameDraft}
                        onChange={(event) => {
                            setNameDraft(event.target.value);
                        }}
                        aria-label={t((d) => d.documentsReview.nameLabel)}
                    />
                    {/* Offered, never applied. What a document is called is the person's decision,
                        like every other value read out of it. */}
                    {suggestedName !== null && suggestedName !== nameDraft && (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="note">{t((d) => d.documentsReview.renameSuggestion)}:</span>
                            <code className="text-xs break-all">{suggestedName}</code>
                            <PanelButton
                                onClick={() => {
                                    setNameDraft(suggestedName);
                                }}
                            >
                                {t((d) => d.documentsReview.renameUseSuggestion)}
                            </PanelButton>
                        </div>
                    )}
                    <ErrorText message={renameError} />
                </div>
            </PanelDialog>
        </AppShell>
    );
}
