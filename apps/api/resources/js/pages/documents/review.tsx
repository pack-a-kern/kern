import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type JSX, type SyntheticEvent } from 'react';
import AppShell from '../../components/AppShell';
import ErrorText from '../../components/ErrorText';
import StatusBadge from '../../components/StatusBadge';
import {
    DocumentContentNotAvailableError,
    ExtractionAlreadyReviewedError,
    acceptDocumentExtraction,
    correctDocumentExtraction,
    fetchDocument,
    fetchDocumentContent,
    fetchDocumentExtractions,
} from '../../api/documentsApi';
import { useDocumentsChannel } from '../../hooks/useDocumentsChannel';
import { useTranslation } from '../../i18n';
import { ApiError } from '../../lib/http';
import { getActiveSubjectId } from '../../lib/subjectStore';
import type { DocumentExtractionSummary, DocumentLifecycleEvent, DocumentSummary } from '../../types/documents';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';
import { Input } from '@/components/ui/input';

/** Statuses for which `GET .../documents/{document}/content` can return bytes
 * (`App\Http\Controllers\Api\V1\Documents\ShowDocumentContentController::CONTENT_AVAILABLE_STATUSES`). */
const CONTENT_AVAILABLE_STATUSES: DocumentSummary['status'][] = ['SCANNED', 'PROCESSING', 'READY'];

const EXTRACTION_STATUS_COLORS: Record<DocumentExtractionSummary['status'], string> = {
    PROPOSED: 'bg-warning/15 text-warning',
    REVIEWED: 'bg-info/10 text-info',
    ACCEPTED: 'bg-success/10 text-success',
    REJECTED: 'bg-muted text-muted-foreground',
};

function ExtractionStatusBadge({ status }: { status: DocumentExtractionSummary['status'] }): JSX.Element {
    const { t } = useTranslation();

    return <Badge className={EXTRACTION_STATUS_COLORS[status]}>{t((d) => d.extractionStatus[status])}</Badge>;
}

function ConfidenceBar({ confidence }: { confidence: number }): JSX.Element {
    const { t } = useTranslation();
    const percent = Math.round(confidence * 100);
    const color = confidence >= 0.85 ? 'bg-success' : confidence >= 0.6 ? 'bg-warning' : 'bg-destructive';

    return (
        <div
            className="flex items-center gap-2"
            role="meter"
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={t((d) => d.documentsReview.confidenceLabel)}
        >
            <div className="h-1.5 w-24 overflow-hidden rounded-full bg-muted">
                <div className={`h-full ${color}`} style={{ width: `${String(percent)}%` }} />
            </div>
            <span className="text-xs tabular-nums text-muted-foreground">{percent}%</span>
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
    const { t } = useTranslation();
    const unrenderableValue = t((d) => d.documentsReview.unrenderableValue);
    const [subjectId] = useState(() => getActiveSubjectId());
    const [document, setDocument] = useState<DocumentSummary | null>(null);
    const [documentError, setDocumentError] = useState<string | null>(null);

    const [extractions, setExtractions] = useState<DocumentExtractionSummary[]>([]);
    const [extractionsCursor, setExtractionsCursor] = useState<string | null>(null);
    const [extractionsError, setExtractionsError] = useState<string | null>(null);
    const [extractionsLoading, setExtractionsLoading] = useState(true);

    const [busyExtractionId, setBusyExtractionId] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    const [correctingId, setCorrectingId] = useState<string | null>(null);
    const [correctionRows, setCorrectionRows] = useState<CorrectionRow[]>([]);

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

    useEffect(() => {
        void (async () => {
            await loadDocument();
            await loadExtractions();
        })();
    }, [loadDocument, loadExtractions]);

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

    const handleEvent = useCallback(
        (event: DocumentLifecycleEvent) => {
            if (event.documentId !== documentId) {
                return;
            }

            void loadDocument();
            void loadExtractions();
        },
        [documentId, loadDocument, loadExtractions],
    );

    useDocumentsChannel(subjectId, handleEvent);

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

    return (
        <AppShell contentClassName="max-w-5xl">
            <Head title={t((d) => d.documentsReview.title)} />
            <Button variant="link" className="h-auto p-0" asChild>
                <Link href="/documents">
                    <ArrowLeft className="size-3.5" /> {t((d) => d.documentsReview.back)}
                </Link>
            </Button>

            <ErrorText message={documentError} />

            {document && (
                <Card>
                    <CardContent className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h1 className="text-lg font-semibold text-foreground">
                                {t((d) => d.documentType[document.type])}
                            </h1>
                            <p className="text-xs text-muted-foreground">
                                {t((d) => d.documentsInbox.received)}:{' '}
                                <time dateTime={document.receivedAt} className="tabular-nums">
                                    {new Date(document.receivedAt).toLocaleString('ro-RO')}
                                </time>{' '}
                                · {document.mimeType}
                            </p>
                            {document.rejectionReason &&
                                (() => {
                                    const reason = document.rejectionReason;

                                    return (
                                        <p className="mt-1 text-xs text-destructive">
                                            {t((d) => d.rejectionReason[reason])}
                                        </p>
                                    );
                                })()}
                        </div>
                        <StatusBadge status={document.status} />
                    </CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>{t((d) => d.documentsReview.source)}</CardTitle>
                    </CardHeader>
                    <CardContent>
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
                                        alt="Previzualizare document"
                                        className="max-h-[32rem] w-full rounded-md border border-border object-contain"
                                    />
                                )}
                                {previewUrl && previewMime === 'application/pdf' && (
                                    <iframe
                                        src={previewUrl}
                                        title="Previzualizare document PDF"
                                        className="h-[32rem] w-full rounded-md border border-border"
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
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{t((d) => d.documentsReview.proposedExtractions)}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <Alert>
                            <AlertDescription>{t((d) => d.documentsReview.noAutoConfirmNotice)}</AlertDescription>
                        </Alert>

                        <ErrorText message={extractionsError} />
                        <ErrorText message={actionError} />

                        {extractionsLoading && (
                            <p className="text-sm text-muted-foreground">{t((d) => d.documentsReview.loading)}</p>
                        )}

                        {!extractionsLoading && extractions.length === 0 && (
                            <p className="rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                {t((d) => d.documentsReview.empty)}
                            </p>
                        )}

                        <ul className="space-y-3">
                            {extractions.map((extraction) => (
                                <li key={extraction.id} className="rounded-md border border-border p-3">
                                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <span className="text-xs font-medium text-muted-foreground">
                                                {t((d) => d.extractorType[extraction.extractorType])}
                                            </span>
                                            <span className="ml-2 text-xs tabular-nums text-muted-foreground/70">
                                                {new Date(extraction.createdAt).toLocaleString('ro-RO')}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ExtractionStatusBadge status={extraction.status} />
                                            <ConfidenceBar confidence={Number(extraction.confidence)} />
                                        </div>
                                    </div>

                                    <Table>
                                        <TableBody>
                                            {Object.entries(extraction.proposedFields).map(([key, value]) => (
                                                <TableRow key={key}>
                                                    <TableCell className="w-1/2 whitespace-normal text-muted-foreground">
                                                        {key}
                                                    </TableCell>
                                                    <TableCell className="whitespace-normal text-right tabular-nums text-foreground">
                                                        {fieldValueAsText(value, unrenderableValue)}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>

                                    {extraction.reviewedBy && (
                                        <p className="mt-2 text-xs text-muted-foreground/70">
                                            {t((d) => d.documentsReview.reviewedBy)} {extraction.reviewedBy} —{' '}
                                            {new Date(extraction.updatedAt).toLocaleString('ro-RO')}
                                        </p>
                                    )}

                                    {extraction.status === 'PROPOSED' && correctingId !== extraction.id && (
                                        <div className="mt-2 flex gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="border-success/30 text-success hover:bg-success/10"
                                                disabled={busyExtractionId === extraction.id}
                                                onClick={() => void handleAccept(extraction)}
                                            >
                                                {t((d) => d.documentsReview.accept)}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                disabled={busyExtractionId === extraction.id}
                                                onClick={() => {
                                                    startCorrection(extraction);
                                                }}
                                            >
                                                {t((d) => d.documentsReview.correct)}
                                            </Button>
                                        </div>
                                    )}

                                    {correctingId === extraction.id && (
                                        <form
                                            onSubmit={(event) => void submitCorrection(event, extraction.id)}
                                            className="mt-3 space-y-2 rounded-md border border-border bg-card p-3"
                                        >
                                            <p className="text-xs font-medium text-muted-foreground">
                                                {t((d) => d.documentsReview.correctionHint)}
                                            </p>

                                            {correctionRows.map((row, index) => (
                                                <div key={index} className="flex gap-2">
                                                    <Input
                                                        type="text"
                                                        aria-label={t((d) => d.documentsReview.fieldNamePlaceholder)}
                                                        placeholder={t((d) => d.documentsReview.fieldNamePlaceholder)}
                                                        value={row.key}
                                                        onChange={(event) => {
                                                            updateCorrectionRow(index, { key: event.target.value });
                                                        }}
                                                        className="w-1/3"
                                                    />
                                                    <Input
                                                        type="text"
                                                        aria-label={t((d) => d.documentsReview.fieldValuePlaceholder)}
                                                        placeholder={t((d) => d.documentsReview.fieldValuePlaceholder)}
                                                        value={row.value}
                                                        onChange={(event) => {
                                                            updateCorrectionRow(index, { value: event.target.value });
                                                        }}
                                                        className="flex-1"
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
                                                        <Trash2 className="size-4 text-destructive" />
                                                    </Button>
                                                </div>
                                            ))}

                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto p-0"
                                                onClick={addCorrectionRow}
                                            >
                                                <Plus className="size-3.5" /> {t((d) => d.documentsReview.addField)}
                                            </Button>

                                            <div className="flex gap-2 pt-1">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={busyExtractionId === extraction.id}
                                                >
                                                    {t((d) => d.documentsReview.submitCorrection)}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={cancelCorrection}
                                                >
                                                    {t((d) => d.documentsReview.cancelCorrection)}
                                                </Button>
                                            </div>
                                        </form>
                                    )}
                                </li>
                            ))}
                        </ul>

                        {extractionsCursor && (
                            <Button variant="link" className="h-auto p-0" onClick={() => void loadMoreExtractions()}>
                                {t((d) => d.documentsReview.loadMore)}
                            </Button>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppShell>
    );
}
