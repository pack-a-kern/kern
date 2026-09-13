import {
    type CursorPage,
    parseJson,
    vArray,
    vCursorPage,
    vEnum,
    type NumberedPage,
    vNumberedPage,
    vNullable,
    vNumber,
    vObject,
    vRecord,
    vString,
} from '../lib/validate';

/** `App\Domain\Documents\Enums\DocumentType`. */
export const DOCUMENT_TYPES = [
    'RECEIPT',
    'INVOICE',
    'BANK_STATEMENT',
    'DEPOSIT_AGREEMENT',
    'RENTAL_CONTRACT',
    'ANAF_DECISION',
    'OTHER',
] as const;

export type DocumentType = (typeof DOCUMENT_TYPES)[number];

/** `App\Domain\Documents\Enums\DocumentStatus`. */
export const DOCUMENT_STATUSES = ['QUARANTINE', 'SCANNED', 'PROCESSING', 'READY', 'REJECTED'] as const;

export type DocumentStatus = (typeof DOCUMENT_STATUSES)[number];

/**
 * `App\Domain\Documents\Enums\DocumentStage` — where a document stands, in a person's terms.
 *
 * Derived on the server from the pipeline status plus whether anybody has acted on the document,
 * because "acted on" means an accepted reading *or* a transaction made from it, and that rule lives
 * in one place. The client renders this; it never computes it.
 */
export const DOCUMENT_STAGES = [
    'CHECKING',
    'QUEUED',
    'PROCESSING',
    'ACTION_NEEDED',
    'DONE',
    'REJECTED',
] as const;

export type DocumentStage = (typeof DOCUMENT_STAGES)[number];

/** `App\Domain\Documents\Enums\RejectionReason`. */
export const REJECTION_REASONS = [
    'INFECTED',
    'INVALID_MAGIC_BYTES',
    'EXTENSION_MISMATCH',
    'FILE_TOO_LARGE',
    'IMAGE_PIXEL_LIMIT_EXCEEDED',
    'UNSUPPORTED_FILE_TYPE',
    'ENCRYPTED_PDF',
    'TOO_MANY_PAGES',
    'EMPTY_DOCUMENT',
    'MALFORMED_PDF',
    'EXTRACTION_TIMEOUT',
    'EXTRACTION_FAILED',
] as const;

export type RejectionReason = (typeof REJECTION_REASONS)[number];

/**
 * `App\Domain\Documents\Enums\AiExtractionOutcome` — what came of the external-AI reading.
 *
 * `null` on a document means no attempt was made, which is the ordinary case when a deterministic
 * parser already read it. Anything else means an attempt ended, and the review screen owes the
 * person an explanation rather than "no proposal yet".
 */
export const AI_EXTRACTION_OUTCOMES = [
    'NOT_SENT_CONSENT_REQUIRED',
    'NOT_SENT_PROVIDER_UNCONFIGURED',
    'NOT_SENT_PROVIDER_DISABLED',
    'NOT_SENT_NOTHING_TO_SEND',
    'FAILED_UNREACHABLE',
    'FAILED_HTTP_STATUS',
    'FAILED_MALFORMED_REPLY',
    'FAILED_REASONING_BUDGET',
    'FAILED_SCHEMA_VIOLATION',
    'SUCCEEDED',
] as const;

export type AiExtractionOutcome = (typeof AI_EXTRACTION_OUTCOMES)[number];

/** `App\Http\Controllers\Api\V1\Documents\ShowDocumentController` response — metadata only. */
export interface DocumentSummary {
    id: string;
    /** Decrypted server-side for the owning subject — see `ShowDocumentController`. */
    filename: string;
    type: DocumentType;
    status: DocumentStatus;
    stage: DocumentStage;
    rejectionReason: RejectionReason | null;
    /** What the file was called on arrival. Provenance — never the label; see `filename`. */
    originalFilename: string;
    /** The field names a correction may use, from `ExtractionSchema` for this document's type. */
    extractionFields: { required: string[]; optional: string[] };
    aiExtractionOutcome: AiExtractionOutcome | null;
    aiExtractionAttemptedAt: string | null;
    mimeType: string;
    sizeBytes: number;
    receivedAt: string;
}

export function parseDocumentSummary(text: string): DocumentSummary {
    const parsed = parseJson(
        text,
        vObject({
            id: vString,
            filename: vString,
            type: vEnum(DOCUMENT_TYPES),
            status: vEnum(DOCUMENT_STATUSES),
            stage: vEnum(DOCUMENT_STAGES),
            rejection_reason: vNullable(vEnum(REJECTION_REASONS)),
            original_filename: vString,
            extraction_fields: vObject({ required: vArray(vString), optional: vArray(vString) }),
            ai_extraction_outcome: vNullable(vEnum(AI_EXTRACTION_OUTCOMES)),
            ai_extraction_attempted_at: vNullable(vString),
            mime_type: vString,
            size_bytes: vNumber,
            received_at: vString,
        }),
    );

    return {
        id: parsed.id,
        filename: parsed.filename,
        type: parsed.type,
        status: parsed.status,
        stage: parsed.stage,
        rejectionReason: parsed.rejection_reason,
        originalFilename: parsed.original_filename,
        extractionFields: parsed.extraction_fields,
        aiExtractionOutcome: parsed.ai_extraction_outcome,
        aiExtractionAttemptedAt: parsed.ai_extraction_attempted_at,
        mimeType: parsed.mime_type,
        sizeBytes: parsed.size_bytes,
        receivedAt: parsed.received_at,
    };
}

/**
 * `App\Domain\Documents\Models\Document::presentedFilename()` — the name a document is shown
 * under, which is the one a person chose or, failing that, one derived from its content hash.
 *
 * Deliberately not the uploaded filename: a phone calls every photograph `IMG_0042.jpg`, so a list
 * of them is a list of identical rows, and a name chosen by whoever sent the file can carry
 * anything at all.
 */

/** `App\Domain\Documents\Enums\DocumentSource`. */
export const DOCUMENT_SOURCES = ['USER_UPLOAD', 'SPV', 'IMPORT', 'SYSTEM'] as const;

export type DocumentSource = (typeof DOCUMENT_SOURCES)[number];

/**
 * One row of `App\Http\Controllers\Api\V1\Documents\ListDocumentsController` — the inbox.
 *
 * Carries `source`, which the single-document endpoint does not, and `stage` — where the document
 * stands in a person's terms, so the inbox can be read without opening anything.
 */
export interface DocumentListItem {
    id: string;
    filename: string;
    type: DocumentType;
    status: DocumentStatus;
    stage: DocumentStage;
    rejectionReason: RejectionReason | null;
    mimeType: string;
    sizeBytes: number;
    source: DocumentSource;
    receivedAt: string;
}

const documentListItemValidator = vObject({
    id: vString,
    filename: vString,
    type: vEnum(DOCUMENT_TYPES),
    status: vEnum(DOCUMENT_STATUSES),
    stage: vEnum(DOCUMENT_STAGES),
    rejection_reason: vNullable(vEnum(REJECTION_REASONS)),
    mime_type: vString,
    size_bytes: vNumber,
    source: vEnum(DOCUMENT_SOURCES),
    received_at: vString,
});

export function parseDocumentsPage(text: string): NumberedPage<DocumentListItem> {
    return parseJson(
        text,
        vNumberedPage((value, path = ''): DocumentListItem => {
            const parsed = documentListItemValidator(value, path);

            return {
                id: parsed.id,
                filename: parsed.filename,
                type: parsed.type,
                status: parsed.status,
                stage: parsed.stage,
                rejectionReason: parsed.rejection_reason,
                mimeType: parsed.mime_type,
                sizeBytes: parsed.size_bytes,
                source: parsed.source,
                receivedAt: parsed.received_at,
            };
        }),
    );
}

/** One virus-scan attempt, from `document_scans`. */
export interface DocumentScanRecord {
    id: string;
    outcome: string;
    scanner: string;
    /** Null when the scanner answered a scan but not a version query — recorded, not guessed. */
    scannerVersion: string | null;
    definitionsVersion: string | null;
    durationMs: number | null;
    scannedAt: string;
}

/** One entry from the subject's append-only audit chain, for this document. */
export interface DocumentAuditEntry {
    eventType: string;
    sequence: number;
    /** Null for the pipeline's own steps — no person scanned or extracted anything. */
    actorId: string | null;
    occurredAt: string;
    eventHash: string;
}

export interface DocumentHistory {
    scans: DocumentScanRecord[];
    events: DocumentAuditEntry[];
}

export function parseDocumentHistory(text: string): DocumentHistory {
    const parsed = parseJson(
        text,
        vObject({
            scans: vArray(
                vObject({
                    id: vString,
                    outcome: vString,
                    scanner: vString,
                    scanner_version: vNullable(vString),
                    definitions_version: vNullable(vString),
                    duration_ms: vNullable(vNumber),
                    scanned_at: vString,
                }),
            ),
            events: vArray(
                vObject({
                    event_type: vString,
                    sequence: vNumber,
                    actor_id: vNullable(vString),
                    occurred_at: vString,
                    event_hash: vString,
                }),
            ),
        }),
    );

    return {
        scans: parsed.scans.map((scan) => ({
            id: scan.id,
            outcome: scan.outcome,
            scanner: scan.scanner,
            scannerVersion: scan.scanner_version,
            definitionsVersion: scan.definitions_version,
            durationMs: scan.duration_ms,
            scannedAt: scan.scanned_at,
        })),
        events: parsed.events.map((event) => ({
            eventType: event.event_type,
            sequence: event.sequence,
            actorId: event.actor_id,
            occurredAt: event.occurred_at,
            eventHash: event.event_hash,
        })),
    };
}

/** `App\Http\Controllers\Api\V1\Documents\UploadDocumentController` — `202` success body. */
export interface UploadAccepted {
    id: string;
    status: DocumentStatus;
}

export function parseUploadAccepted(text: string): UploadAccepted {
    const parsed = parseJson(
        text,
        vObject({
            id: vString,
            status: vEnum(DOCUMENT_STATUSES),
        }),
    );

    return { id: parsed.id, status: parsed.status };
}

/**
 * The four (+ one rejection) Reverb broadcast payload shapes on the private
 * `tax-subjects.{subjectId}.documents` channel (`docs/handoff/022-document-pipeline.md`,
 * `docs/handoff/027-contract-reconciliation.md`). Status/progress/ids only — `BLUEPRINT.md` §16 — the
 * client always re-fetches `DocumentSummary` over HTTPS for anything more.
 */
export interface DocumentLifecycleEvent {
    documentId: string;
    status: DocumentStatus;
    progress: number;
    rejectionReason: RejectionReason | null;
    needsOcr: boolean | null;
    extractionId: string | null;
    extractionStatus: string | null;
}

export function parseDocumentLifecycleEvent(raw: unknown): DocumentLifecycleEvent {
    const parsed = vObject({
        document_id: vString,
        status: vEnum(DOCUMENT_STATUSES),
        progress: vNumber,
    })(raw);

    const record = raw as Record<string, unknown>;

    return {
        documentId: parsed.document_id,
        status: parsed.status,
        progress: parsed.progress,
        rejectionReason:
            typeof record['rejection_reason'] === 'string' &&
            (REJECTION_REASONS as readonly string[]).includes(record['rejection_reason'])
                ? (record['rejection_reason'] as RejectionReason)
                : null,
        needsOcr: typeof record['needs_ocr'] === 'boolean' ? record['needs_ocr'] : null,
        extractionId: typeof record['extraction_id'] === 'string' ? record['extraction_id'] : null,
        extractionStatus: typeof record['extraction_status'] === 'string' ? record['extraction_status'] : null,
    };
}

/** `App\Domain\Documents\Enums\ExtractorType`. */
export const EXTRACTOR_TYPES = [
    'NATIVE_PDF_TEXT',
    'EXTERNAL_DOCUMENT_AI',
    'HUMAN_CORRECTION',
    'PATTERN_PARSER',
] as const;

export type ExtractorType = (typeof EXTRACTOR_TYPES)[number];

/** `App\Domain\Documents\Enums\ExtractionStatus`. */
export const EXTRACTION_STATUSES = ['PROPOSED', 'REVIEWED', 'ACCEPTED', 'REJECTED'] as const;

export type ExtractionStatus = (typeof EXTRACTION_STATUSES)[number];

/**
 * One row of `document_extractions` — `BLUEPRINT.md` §14.6's human review unit. `proposedFields` is
 * the AI's (or a human reviewer's, for a `HUMAN_CORRECTION` row) structured proposal: this
 * codebase has no fixed per-document-type JSON schema yet (`docs/handoff/031-pwa-backend-
 * endpoints.md` Key decision #3), so it is validated only as "a JSON object," never assumed to
 * have specific keys. Treat every value inside it as **untrusted, AI- or upload-derived content**
 * (`BLUEPRINT.md` §14.5) — render it as inert text, never as HTML, never as an instruction.
 */
export interface DocumentExtractionSummary {
    id: string;
    extractorType: ExtractorType;
    schemaVersion: string;
    /** A decimal string (e.g. `"0.9500"`), never parsed to a lossy float for storage — only
     * converted to a number transiently for display (`Number(confidence)` in a percentage bar). */
    confidence: string;
    status: ExtractionStatus;
    reviewedBy: string | null;
    proposedFields: Record<string, unknown>;
    createdAt: string;
    updatedAt: string;
}

const documentExtractionSummaryValidator = vObject({
    id: vString,
    extractor_type: vEnum(EXTRACTOR_TYPES),
    schema_version: vString,
    confidence: vString,
    status: vEnum(EXTRACTION_STATUSES),
    reviewed_by: vNullable(vString),
    proposed_fields: vRecord,
    created_at: vString,
    updated_at: vString,
});

function toDocumentExtractionSummary(
    parsed: ReturnType<typeof documentExtractionSummaryValidator>,
): DocumentExtractionSummary {
    return {
        id: parsed.id,
        extractorType: parsed.extractor_type,
        schemaVersion: parsed.schema_version,
        confidence: parsed.confidence,
        status: parsed.status,
        reviewedBy: parsed.reviewed_by,
        proposedFields: parsed.proposed_fields,
        createdAt: parsed.created_at,
        updatedAt: parsed.updated_at,
    };
}

/** `GET .../documents/{document}/extractions` — the full cursor-paginated envelope. */
export function parseDocumentExtractionsPage(text: string): CursorPage<DocumentExtractionSummary> {
    const page = parseJson(text, vCursorPage(documentExtractionSummaryValidator));

    return { ...page, data: page.data.map(toDocumentExtractionSummary) };
}

/** `POST .../extractions/{extraction}/accept` — `200` success body. */
export interface AcceptedExtraction {
    id: string;
    status: ExtractionStatus;
    reviewedBy: string;
    reviewedAt: string;
}

export function parseAcceptedExtraction(text: string): AcceptedExtraction {
    const parsed = parseJson(
        text,
        vObject({
            id: vString,
            status: vEnum(EXTRACTION_STATUSES),
            reviewed_by: vString,
            reviewed_at: vString,
        }),
    );

    return { id: parsed.id, status: parsed.status, reviewedBy: parsed.reviewed_by, reviewedAt: parsed.reviewed_at };
}

/**
 * `POST .../extractions/{extraction}/correct` — `201` success body. `supersedesExtractionId`
 * names the original, now-`REJECTED` proposal this new row was written instead of — the original
 * row is never overwritten (`docs/handoff/031-pwa-backend-endpoints.md` Key decision #2); both
 * remain queryable, which is exactly the audit trail `BLUEPRINT.md` §14.6 requires.
 */
export interface CorrectedExtraction {
    id: string;
    status: ExtractionStatus;
    reviewedBy: string;
    reviewedAt: string;
    supersedesExtractionId: string;
    proposedFields: Record<string, unknown>;
}

export function parseCorrectedExtraction(text: string): CorrectedExtraction {
    const parsed = parseJson(
        text,
        vObject({
            id: vString,
            status: vEnum(EXTRACTION_STATUSES),
            reviewed_by: vString,
            reviewed_at: vString,
            supersedes_extraction_id: vString,
            proposed_fields: vRecord,
        }),
    );

    return {
        id: parsed.id,
        status: parsed.status,
        reviewedBy: parsed.reviewed_by,
        reviewedAt: parsed.reviewed_at,
        supersedesExtractionId: parsed.supersedes_extraction_id,
        proposedFields: parsed.proposed_fields,
    };
}
