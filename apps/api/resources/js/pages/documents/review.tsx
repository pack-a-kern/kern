import { Head, Link } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import {
    Activity,
    ArrowLeft,
    Banknote,
    CalendarDays,
    Check,
    Coins,
    CreditCard,
    Eraser,
    FileText,
    FileType2,
    FileUp,
    Gauge,
    Hash,
    IdCard,
    Info,
    Landmark,
    ListTree,
    MapPin,
    MoreHorizontal,
    Pencil,
    Plus,
    ScrollText,
    Store,
    Tag,
    Trash2,
    User,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type JSX, type ReactNode, type SyntheticEvent } from 'react';
import AppShell from '../../components/AppShell';
import CreateTransactionDialog from '../../components/documents/CreateTransactionDialog';
import RentalDialog from '../../components/rentals/RentalDialog';
import { rentalFormFromReading } from '../../lib/rentalForm';
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
    DocumentScanRecord,
    DocumentLifecycleEvent,
    DocumentSummary,
} from '../../types/documents';
import { documentTypeLabel } from '../../types/documents';
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
 * (`Kern\Documents\Http\Controllers\ShowDocumentContentController::CONTENT_AVAILABLE_STATUSES`). */
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
function ConfidenceBar({ confidence }: { confidence: string | null }): JSX.Element {
    const { t } = useTranslation();

    // Said in words, not drawn. A bar at some default position is a claim about how well the
    // document was read, made by nobody — and it looked identical to a model that had actually
    // assessed a blurred photograph and arrived at that number.
    if (confidence === null) {
        return <span className="note">{t((d) => d.documentsReview.confidenceUnreported)}</span>;
    }

    const value = Number(confidence);
    const percent = Math.round(value * 100);
    // `--attention` is the palette's own "a person should look at this" colour, so a middling
    // confidence is marked in the same hue as everything else asking for attention.
    const tone = value >= 0.85 ? 'bg-success' : value >= 0.6 ? 'bg-[var(--attention)]' : 'bg-destructive';

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

/**
 * One field of a reading, drawn according to its shape.
 *
 * A reading used to be a flat map of strings and this was a line of text. It can now hold a group
 * (an invoice's issuer) or a list of groups (its lines, a statement's rows), and those have to be
 * drawn as what they are — the fallback of serialising them turned a statement's eighty-four rows
 * into a single unreadable line of JSON.
 *
 * Everything here is inert text, as before: values come from an AI proposal or a human
 * transcription of a document, both untrusted (`BLUEPRINT.md` §14.5), so they reach JSX as text
 * content that React escapes and never as markup.
 */
function FieldValue(props: { value: unknown; unrenderable: string; rowLabel: (count: number) => string }): JSX.Element {
    const { value } = props;

    if (Array.isArray(value)) {
        // `Array.isArray` narrows to `any[]`, and a reading's entries are untrusted content that has
        // to stay `unknown` until `fieldValueAsText` narrows it deliberately.
        const rows: unknown[] = value;

        return (
            <div className="min-w-0">
                <p className="note mb-1">{props.rowLabel(value.length)}</p>
                <div className="max-h-72 overflow-auto rounded-xl border border-border">
                    <table className="w-full text-xs">
                        <tbody>
                            {rows.map((entry, index) => (
                                <tr key={index} className="border-border border-t first:border-t-0">
                                    <td className="p-2 align-top">
                                        <FieldGroup value={entry} unrenderable={props.unrenderable} inline />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    if (typeof value === 'object' && value !== null) {
        return <FieldGroup value={value} unrenderable={props.unrenderable} />;
    }

    return <span className="tabular-nums break-words">{fieldValueAsText(value, props.unrenderable)}</span>;
}

/**
 * A reading's list as a table: one column per field, one row per entry.
 *
 * Columns come from the rows themselves rather than from the schema, in the order they first
 * appear, because a model may omit a field on every line — and a column of nothing but dashes is
 * worse than no column. A row missing one the others have gets an empty cell, so the grid stays
 * aligned and a gap is visible as a gap.
 *
 * Numbers are right-aligned and tabular so figures line up down the column, which is the whole
 * reason a table beats a list here: an amount that does not belong is visible at a glance.
 *
 * Headers are the JSON keys themselves, untranslated. Everywhere else a person reads a *reading*;
 * here they are checking what the model returned, and a friendly label would hide the very thing
 * being checked — whether the key is the one that was asked for.
 */
function RowTable(props: { rows: unknown[]; unrenderable: string }): JSX.Element {
    const rows = props.rows.filter(
        (row): row is Record<string, unknown> => typeof row === 'object' && row !== null && !Array.isArray(row),
    );

    const columns: string[] = [];

    for (const row of rows) {
        for (const key of Object.keys(row)) {
            if (!columns.includes(key)) {
                columns.push(key);
            }
        }
    }

    const numeric = new Set(
        columns.filter((key) =>
            rows.every((row) => {
                const value = row[key];

                return value === undefined || value === null || value === '' || !Number.isNaN(Number(value));
            }),
        ),
    );

    if (columns.length === 0) {
        return <p className="note">—</p>;
    }

    return (
        <table className="row-table">
            <thead>
                <tr>
                    <th scope="col" className="row-table-index">
                        #
                    </th>
                    {columns.map((key) => (
                        <th key={key} scope="col" className={numeric.has(key) ? 'text-right' : undefined}>
                            {/* The wire name, not a translated label. This table is where a person
                                checks what the model actually returned, and a pretty header hides
                                the one thing being checked — whether the key is the one asked for. */}
                            {key}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {rows.map((row, index) => (
                    <tr key={index}>
                        <td className="row-table-index">{index + 1}</td>
                        {columns.map((key) => (
                            <td key={key} className={numeric.has(key) ? 'text-right tabular-nums' : undefined}>
                                {row[key] === undefined ? '' : fieldValueAsText(row[key], props.unrenderable)}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/**
 * What one reading cost, on request.
 *
 * Behind an icon rather than on the line: how long a model took and what it spent is the question
 * an operator asks while choosing between models, not something a person reviewing an invoice needs
 * in front of them. Absent entirely for a reading nobody measured — a parser, or a provider that
 * keeps no accounting — because an empty tooltip is worse than no icon.
 */
/** One distinct scan result, and how many of the document's files came back with it. */
interface ScanGroup extends DocumentScanRecord {
    fileCount: number;
}

/**
 * The scan rows, one entry per distinct result.
 *
 * Scanning is per file, so a four-page upload produces four rows — same engine, same signature
 * database, the same second — and printed one under another they read as four separate facts about
 * four separate things, which is exactly what they are not.
 *
 * Rows that agree collapse into one that says how many files it covers. Rows that disagree stay
 * apart: a document where one file came back different is the entire reason these are kept, and it
 * must not be averaged away. The full per-file list is in the audit chain either way.
 */
function groupScans(scans: DocumentScanRecord[]): ScanGroup[] {
    const groups = new Map<string, ScanGroup>();

    for (const scan of scans) {
        const key = [scan.outcome, scan.scanner, scan.scannerVersion, scan.definitionsVersion].join('|');
        const existing = groups.get(key);

        if (existing === undefined) {
            groups.set(key, { ...scan, fileCount: 1 });
            continue;
        }

        existing.fileCount += 1;
        // The last file to be cleared is when the document as a whole was cleared.
        existing.scannedAt = scan.scannedAt > existing.scannedAt ? scan.scannedAt : existing.scannedAt;
    }

    return [...groups.values()];
}

function ReadingCost({ extraction }: { extraction: DocumentExtractionSummary }): JSX.Element {
    const { t, locale } = useTranslation();

    // Always rendered, even for a reading stored before the timing and token columns existed: the
    // model that produced it is the part a reviewer actually asks about, and it is always known.
    const lines = [
        `${t((d) => d.documentsReview.readBy)} ${extraction.modelName ?? extraction.extractorVersion}`,
        extraction.durationMs === null
            ? null
            : t((d) => d.documentsReview.tookSeconds).replace('{seconds}', (extraction.durationMs / 1000).toFixed(1)),
        extraction.promptTokens === null && extraction.completionTokens === null
            ? null
            : t((d) => d.documentsReview.tokensUsed)
                  .replace('{prompt}', (extraction.promptTokens ?? 0).toLocaleString(locale))
                  .replace('{completion}', (extraction.completionTokens ?? 0).toLocaleString(locale)),
    ].filter((line): line is string => line !== null);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {/* A span, not a button: it is not actionable, and a tab stop in front of every
                    reading for information nobody needs while reviewing would be in the way. */}
                <span className="note inline-flex items-center" aria-hidden="true">
                    <Info className="size-3" />
                </span>
            </TooltipTrigger>
            <TooltipContent side="top">
                <span className="grid gap-0.5">
                    {lines.map((line) => (
                        <span key={line}>{line}</span>
                    ))}
                </span>
            </TooltipContent>
        </Tooltip>
    );
}

/** A group's own fields — one per line, or run together for a row inside a list. */
function FieldGroup(props: { value: unknown; unrenderable: string; inline?: boolean }): JSX.Element {
    // Typed explicitly: `Object.entries` on an `object` widens its values to `any`, and a reading's
    // values are untrusted content that must stay `unknown` until `fieldValueAsText` narrows it.
    const entries: [string, unknown][] =
        typeof props.value === 'object' && props.value !== null
            ? Object.entries(props.value as Record<string, unknown>)
            : [];

    return (
        <div className={props.inline === true ? 'flex flex-wrap gap-x-3 gap-y-1' : 'grid gap-1'}>
            {entries.map(([key, nested]) => (
                <span key={key} className="min-w-0">
                    <span className="note">{key}: </span>
                    <span className="tabular-nums break-words">{fieldValueAsText(nested, props.unrenderable)}</span>
                </span>
            ))}
        </div>
    );
}

/**
 * The icon that says what kind of value a field holds.
 *
 * By meaning, not by name: every money field gets the same mark whether it is a total, a VAT line
 * or a unit price, so a reader finds the amount on an unfamiliar document type without reading the
 * labels. A field nobody has classified gets none rather than a misleading one.
 */
const FIELD_ICONS: Record<string, ComponentType<{ className?: string }>> = {
    amount: Banknote,
    net_amount: Banknote,
    vat_amount: Banknote,
    total_price: Banknote,
    unit_price: Banknote,
    vat_price: Banknote,
    date: CalendarDays,
    due_date: CalendarDays,
    contract_end_date: CalendarDays,
    contract_rental_address: MapPin,
    transaction_date: CalendarDays,
    value_date: CalendarDays,
    period_start: CalendarDays,
    period_end: CalendarDays,
    currency: Coins,
    confidence: Gauge,
    document_number: Hash,
    product_code: Hash,
    reference: Hash,
    quantity: Hash,
    amount_paid: Banknote,
    amount_stated: Banknote,
    gross_amount: Banknote,
    tax_withheld: Banknote,
    exchange_rate: Coins,
    currency_paid: Coins,
    currency_stated: Coins,
    payer: User,
    beneficiary: User,
    issuer: Store,
    institution_name: Landmark,
    recipient: User,
    counterparty: User,
    tax_id: IdCard,
    iban: CreditCard,
    account_iban: CreditCard,
    account_reference: CreditCard,
    items: ListTree,
    transactions: ListTree,
    payments: ListTree,
    statement: Landmark,
    description: FileText,
    suggested_filename: FileText,
    category: Tag,
};

function fieldIcon(key: string): ReactNode {
    const Icon = FIELD_ICONS[key];

    return Icon === undefined ? null : <Icon className="size-3" />;
}

interface CorrectionRow {
    key: string;
    value: string;
}

/**
 * The reading as rows this form can actually edit.
 *
 * Scalars only, and only ones the schema offers for correction. A group or a list has no honest
 * representation here — the form is a name and a text box, so an invoice's `items` became a
 * serialised blob in an input next to a picker that could not name it. Better to leave what cannot
 * be edited out of the form entirely: it is still shown, in full, in the reading above.
 */
function fieldsToRows(
    fields: Record<string, unknown>,
    correctable: readonly string[],
    unrenderable: string,
): CorrectionRow[] {
    const rows = Object.entries(fields)
        .filter(([key, value]) => correctable.includes(key) && typeof value !== 'object')
        .map(([key, value]) => ({ key, value: fieldValueAsText(value, unrenderable) }));

    return rows.length > 0 ? rows : [{ key: '', value: '' }];
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
     * The pipeline still has the document — scanning it, queued behind another reading, or reading
     * it right now.
     *
     * All three, not just `PROCESSING`. A freshly uploaded document sits in `QUEUED` for as long as
     * the queue is busy, and the screen used to say "no proposed extraction yet" for that whole
     * time, which reads as "nothing is coming" when in fact everything is. `DocumentStage` already
     * draws that distinction for the inbox, so the same three stages answer it here.
     *
     * `PROCESSING` is claimed by the retry endpoint before it dispatches, so this is true from the
     * moment the request is accepted rather than from whenever a worker picks the job up — the gap
     * in between is exactly where repeated clicks used to slip through.
     */
    const inFlightStage =
        document !== null &&
        (document.stage === 'CHECKING' || document.stage === 'QUEUED' || document.stage === 'PROCESSING')
            ? document.stage
            : null;
    const isReading = inFlightStage !== null;

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
                  documentTypeLabel(document.type, t),
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
    const [creatingRental, setCreatingRental] = useState(false);

    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    /**
     * Which page of the document is on screen.
     *
     * By index, not by id: a page removed while this screen is open would leave an id pointing at
     * nothing, and clamping an index to the list that is actually there is the behaviour that keeps
     * showing a document rather than an error.
     *
     * The document id is held beside it so opening a different document starts at its first page
     * without an effect that resets state after a render has already drawn page 3 of something
     * else.
     */
    /** The list a person asked to see in full — an invoice's lines, a statement's rows. */
    const [rowsShown, setRowsShown] = useState<{ label: string; rows: unknown[] } | null>(null);
    const [openPage, setOpenPage] = useState({ documentId, index: 0 });
    const pageIndex = openPage.documentId === documentId ? openPage.index : 0;

    const setPageIndex = (index: number): void => {
        setOpenPage({ documentId, index });
    };
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

    const parts = document?.parts ?? [];
    // Clamped rather than trusted: a re-fetch that returns fewer pages than before must not leave
    // this screen asking for a page that is no longer there.
    const currentPage = parts.length === 0 ? 0 : Math.min(pageIndex, parts.length - 1);
    const part = parts[currentPage];

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
                const blob = await fetchDocumentContent(subjectId, documentId, part?.id);

                if (cancelledRef.current) {
                    return;
                }

                const url = URL.createObjectURL(blob);
                previewUrlRef.current = url;
                setPreviewUrl(url);
                setPreviewMime(blob.type || part?.mimeType || document.mimeType || '');
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
    }, [document, part, subjectId, documentId, t]);

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
            // The document carries whether an import may now be offered, and accepting is precisely
            // what changes that answer.
            await loadDocument();
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
        setCorrectionRows(
            fieldsToRows(
                extraction.proposedFields,
                correctableFields.map((field) => field.name),
                unrenderableValue,
            ),
        );
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

    /**
     * @param event the form submit that triggered this, or null when a dialog button did
     */
    async function submitCorrection(
        event: SyntheticEvent<HTMLFormElement> | null,
        extractionId: string,
    ): Promise<void> {
        event?.preventDefault();

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

    // What an import would draw on. A bank statement's rows arrive under `transactions`, and
    // turning a batch of those into a ledger is not the same gesture as confirming one figure — so
    // the two are told apart here, and only the single case has a dialog behind it so far.
    const acceptedReading =
        extractions.find((candidate) => candidate.status === 'ACCEPTED' || candidate.status === 'REVIEWED') ?? null;
    const acceptedRows = acceptedReading?.proposedFields.transactions;
    const statementRows = Array.isArray(acceptedRows) ? acceptedRows : null;

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
                                {/* Both labelled actions need room the card head does not have on a
                                    phone. Same two, same order, behind one control — not a
                                    different set, which would make the two screens disagree about
                                    what you can do to a document.

                                    Creating a transaction is deliberately not among them: it is not
                                    a thing you do to a document, it is what you do with a reading
                                    you have accepted, so it belongs beside that reading. */}
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
                                    {documentTypeLabel(document.type, t)}
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
                                            {groupScans(history.scans).map((scan) => (
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
                                                        {/* Only when it covers more than the one file:
                                                            on a single-file document the count is
                                                            noise, and every document used to be one. */}
                                                        {scan.fileCount > 1 && (
                                                            <Detail label={t((d) => d.documentsReview.scanFilesLabel)}>
                                                                {String(scan.fileCount)}
                                                            </Detail>
                                                        )}
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

                            {parts.length > 1 && (
                                <div className="mb-3 flex flex-wrap items-center gap-2">
                                    {parts.map((page, index) => (
                                        <button
                                            key={page.id}
                                            type="button"
                                            onClick={() => {
                                                setPageIndex(index);
                                            }}
                                            aria-current={index === currentPage}
                                            className={cn(
                                                'shell-control',
                                                index === currentPage && 'bg-secondary text-accent-700',
                                            )}
                                        >
                                            {t((d) => d.documentsReview.page).replace(
                                                '{number}',
                                                String(page.position),
                                            )}
                                        </button>
                                    ))}
                                    <span className="note">
                                        {t((d) => d.documentsCapture.pageCount).replace(
                                            '{count}',
                                            String(parts.length),
                                        )}
                                    </span>
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
                            {inFlightStage !== null && (
                                <div className="rounded-md border border-border bg-muted px-4 py-3">
                                    {/* The stage names itself, in the same words the inbox uses for
                                        it — one vocabulary for "where is my document", not one per
                                        screen. The line under it says what that stage is waiting on. */}
                                    <Status tone="warn">{t((d) => d.documentStage)[inFlightStage]}</Status>
                                    <p className="note mt-2">{t((d) => d.documentsReview.stageHint)[inFlightStage]}</p>
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

                                        {(document.aiModelName !== null || document.aiModelProvider !== null) && (
                                            <p className="note">
                                                {t((d) => d.documentsReview.askedModel)}{' '}
                                                <span className="font-medium">
                                                    {document.aiModelName ?? document.aiModelProvider}
                                                </span>
                                            </p>
                                        )}

                                        {/* Folded away by default: it is the answer to "why was it
                                            refused", which only matters once someone asks. Shown
                                            verbatim — a reply reformatted to look tidy is no longer
                                            evidence of what the model returned. */}
                                        {document.aiReply !== null && document.aiReply !== '' && (
                                            <details className="mt-2">
                                                <summary className="note cursor-pointer select-none">
                                                    {t((d) => d.documentsReview.showAiReply)}
                                                </summary>
                                                <pre className="mt-2 max-h-80 overflow-auto rounded-xl border border-border bg-secondary p-3 text-xs whitespace-pre-wrap">
                                                    {document.aiReply}
                                                </pre>
                                            </details>
                                        )}

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
                                            {/* Which reader, and what the reading cost, behind one
                                                icon. It was on the line as well until the two said
                                                the same thing twice — the tooltip names the model
                                                first, so the line does not have to. */}
                                            <ReadingCost extraction={extraction} />
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
                                            <ConfidenceBar confidence={extraction.confidence} />
                                        </div>

                                        <DetailList wide>
                                            {Object.entries(extraction.proposedFields).map(([key, value]) => (
                                                <Detail
                                                    key={key}
                                                    label={t((d) => d.extractionField)[key] ?? key}
                                                    icon={fieldIcon(key)}
                                                >
                                                    {/* A list is summarised, not spilled: a reading
                                                        with forty lines would bury the dozen values
                                                        a person is actually checking. The rows are
                                                        one click away. */}
                                                    {Array.isArray(value) ? (
                                                        <button
                                                            type="button"
                                                            className="link-quiet"
                                                            onClick={() => {
                                                                setRowsShown({ label: key, rows: value });
                                                            }}
                                                        >
                                                            {t((d) => d.documentsReview.rowCount).replace(
                                                                '{count}',
                                                                String(value.length),
                                                            )}
                                                        </button>
                                                    ) : (
                                                        <FieldValue
                                                            value={value}
                                                            unrenderable={unrenderableValue}
                                                            rowLabel={(count) =>
                                                                t((d) => d.documentsReview.rowCount).replace(
                                                                    '{count}',
                                                                    String(count),
                                                                )
                                                            }
                                                        />
                                                    )}
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
                                    </li>
                                ))}
                            </ul>

                            {/* The one place a document turns into money, and it sits under the
                                reading rather than in the card at the top of the screen — because
                                what is being imported is the reading, not the file. Which of the
                                three it is comes from the server, so this cannot offer a button the
                                endpoint would then refuse. */}
                            {document !== null && document.transactionImport !== 'UNAVAILABLE' && (
                                <div className="border-border border-t pt-3">
                                    {document.transactionImport === 'AWAITING_ACCEPTANCE' && (
                                        <p className="note">{t((d) => d.documentsReview.importAwaitingAcceptance)}</p>
                                    )}

                                    {document.transactionImport === 'IMPORTED' && (
                                        <p className="note">
                                            {document.type === 'CONTRACT'
                                                ? t((d) => d.documentsReview.rentalExisting)
                                                : t((d) => d.documentsReview.createTransactionExisting)}
                                        </p>
                                    )}

                                    {/* A contract is owed every period for as long as it runs, so it
                                        becomes a rental rather than one transaction — the endpoint
                                        refuses the transaction import for it. */}
                                    {document.transactionImport === 'AVAILABLE' &&
                                        (document.type === 'CONTRACT' ? (
                                            <Button
                                                size="sm"
                                                onClick={() => {
                                                    setCreatingRental(true);
                                                }}
                                            >
                                                {t((d) => d.documentsReview.createRental)}
                                            </Button>
                                        ) : statementRows === null ? (
                                            <Button
                                                size="sm"
                                                onClick={() => {
                                                    setCreatingTransaction(true);
                                                }}
                                            >
                                                {t((d) => d.documentsReview.importTransaction)}
                                            </Button>
                                        ) : (
                                            <p className="note">
                                                {t((d) => d.documentsReview.importBulkPending).replace(
                                                    '{count}',
                                                    String(statementRows.length),
                                                )}
                                            </p>
                                        ))}
                                </div>
                            )}
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
                        // The document now has its transaction, which is what closes the import off.
                        void loadDocument();
                    }}
                />
            ) : null}
            {creatingRental && document !== null ? (
                <RentalDialog
                    subjectId={subjectId}
                    options={null}
                    editing={null}
                    initial={rentalFormFromReading(bestReading)}
                    documentId={document.id}
                    open={creatingRental}
                    onOpenChange={setCreatingRental}
                    onSaved={() => {
                        void loadHistory();
                        void loadDocument();
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
                        <X className="size-3.5" aria-hidden="true" />
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
                            <Eraser className="size-3.5" aria-hidden="true" />
                            {t((d) => d.documentsReview.renameCleared)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={savingName}
                            onClick={() => {
                                void handleRename(nameDraft);
                            }}
                        >
                            <Check className="size-3.5" aria-hidden="true" />
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

            {/* Correcting a reading is a task of its own, so it gets a surface of its own. Unfolded
                inside the list it pushed every later reading off the screen, and the person editing
                could no longer see the values they were correcting against. */}
            <PanelDialog
                open={correctingId !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        cancelCorrection();
                    }
                }}
                size="lg"
                title={t((d) => d.documentsReview.correct)}
                description={t((d) => d.documentsReview.correctionHint)}
                actions={
                    <>
                        <PanelButton onClick={cancelCorrection}>
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.documentsReview.cancelCorrection)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={correctingId !== null && busyExtractionId === correctingId}
                            onClick={() => {
                                if (correctingId !== null) {
                                    void submitCorrection(null, correctingId);
                                }
                            }}
                        >
                            <Check className="size-3.5" aria-hidden="true" />
                            {t((d) => d.documentsReview.submitCorrection)}
                        </PanelButton>
                    </>
                }
            >
                {correctableFields.length === 0 ? (
                    <p className="note">{t((d) => d.documentsReview.noCorrectableFields)}</p>
                ) : (
                    <div className="space-y-2">
                        <p className="note">{t((d) => d.documentsReview.correctionHint)}</p>

                        {correctionRows.map((row, index) => (
                            <div key={index} className="field-row">
                                {/* The field names this document type
                                actually has, not free text: a typed
                                "amout" is accepted, stored, and useless
                                to everything downstream. */}
                                <PanelSelect
                                    aria-label={t((d) => d.documentsReview.fieldNamePlaceholder)}
                                    placeholder={t((d) => d.documentsReview.fieldNamePlaceholder)}
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
                                        label: t((d) => d.extractionField)[field.name] ?? field.name,
                                        description: field.required
                                            ? t((d) => d.documentsReview.fieldRequired)
                                            : t((d) => d.documentsReview.fieldOptional),
                                    }))}
                                />
                                <PanelInput
                                    type="text"
                                    aria-label={t((d) => d.documentsReview.fieldValuePlaceholder)}
                                    placeholder={t((d) => d.documentsReview.fieldValuePlaceholder)}
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
                    </div>
                )}
            </PanelDialog>

            {/* A reading's list, in full and on request. Summarised in the reading itself because a
                forty-line invoice would bury the dozen values a person is actually checking. */}
            <PanelDialog
                open={rowsShown !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRowsShown(null);
                    }
                }}
                size="lg"
                title={rowsShown === null ? '' : (t((d) => d.extractionField)[rowsShown.label] ?? rowsShown.label)}
                description={t((d) => d.documentsReview.rowCount).replace(
                    '{count}',
                    String(rowsShown?.rows.length ?? 0),
                )}
                actions={
                    <PanelButton
                        onClick={() => {
                            setRowsShown(null);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.common.close)}
                    </PanelButton>
                }
            >
                <div className="modal-scroll">
                    <RowTable rows={rowsShown?.rows ?? []} unrenderable={unrenderableValue} />
                </div>
            </PanelDialog>
        </AppShell>
    );
}
