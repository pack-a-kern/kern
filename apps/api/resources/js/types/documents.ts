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
    'ACCOUNT_STATEMENT',
    'TAX_DOCUMENT',
    'CONTRACT',
    'CORPORATE',
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
    'TO_IMPORT',
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

/**
 * `App\Domain\Documents\Enums\TransactionImportState` — whether this document may still become a
 * transaction.
 *
 * Decided on the server, never re-derived here: the button and the endpoint's own refusal have to
 * be the same rule, and a second copy would drift into promising something the server declines.
 */
export const TRANSACTION_IMPORT_STATES = ['UNAVAILABLE', 'AWAITING_ACCEPTANCE', 'AVAILABLE', 'IMPORTED'] as const;

export type TransactionImportState = (typeof TRANSACTION_IMPORT_STATES)[number];

/** One file of a document — a page of it, in the order the pages are in. */
export interface DocumentPart {
    id: string;
    /** 1-based, the same number the review screen shows. */
    position: number;
    originalFilename: string;
    mimeType: string;
    sizeBytes: number;
    /** Per file, because scanning is: a document can be refused because one page was. */
    status: DocumentStatus;
    rejectionReason: RejectionReason | null;
}

/** `App\Http\Controllers\Api\V1\Documents\ShowDocumentController` response — metadata only. */
export interface DocumentSummary {
    id: string;
    /** Decrypted server-side for the owning subject — see `ShowDocumentController`. */
    filename: string;
    /** Null until something has read the document; a person can correct it on review. */
    type: DocumentType | null;
    status: DocumentStatus;
    stage: DocumentStage;
    rejectionReason: RejectionReason | null;
    /** What the first file was called on arrival. Provenance — never the label; see `filename`. */
    originalFilename: string | null;
    /** The field names a correction may use, from `ExtractionSchema` for this document's type. */
    extractionFields: { required: string[]; optional: string[] };
    aiExtractionOutcome: AiExtractionOutcome | null;
    aiExtractionAttemptedAt: string | null;
    /** What the model last returned — shown when a reading was refused, so it can be diagnosed. */
    aiReply: string | null;
    /** Which model was asked last — recorded even when the attempt failed. */
    aiModelProvider: string | null;
    aiModelName: string | null;
    /** Whether an import is offered, and if not, why not. See `TRANSACTION_IMPORT_STATES`. */
    transactionImport: TransactionImportState;
    mimeType: string | null;
    /** Every part's size added up — what the document weighs, not what page 1 weighs. */
    sizeBytes: number;
    /** The files this document is made of, in page order. Never empty for a stored document. */
    parts: DocumentPart[];
    receivedAt: string;
}

export function parseDocumentSummary(text: string): DocumentSummary {
    const parsed = parseJson(
        text,
        vObject({
            id: vString,
            filename: vString,
            type: vNullable(vEnum(DOCUMENT_TYPES)),
            status: vEnum(DOCUMENT_STATUSES),
            stage: vEnum(DOCUMENT_STAGES),
            rejection_reason: vNullable(vEnum(REJECTION_REASONS)),
            original_filename: vNullable(vString),
            extraction_fields: vObject({ required: vArray(vString), optional: vArray(vString) }),
            ai_extraction_outcome: vNullable(vEnum(AI_EXTRACTION_OUTCOMES)),
            ai_extraction_attempted_at: vNullable(vString),
            ai_reply: vNullable(vString),
            ai_model_provider: vNullable(vString),
            ai_model_name: vNullable(vString),
            transaction_import: vEnum(TRANSACTION_IMPORT_STATES),
            mime_type: vNullable(vString),
            size_bytes: vNumber,
            parts: vArray(
                vObject({
                    id: vString,
                    position: vNumber,
                    original_filename: vString,
                    mime_type: vString,
                    size_bytes: vNumber,
                    status: vEnum(DOCUMENT_STATUSES),
                    rejection_reason: vNullable(vEnum(REJECTION_REASONS)),
                }),
            ),
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
        aiReply: parsed.ai_reply,
        aiModelProvider: parsed.ai_model_provider,
        aiModelName: parsed.ai_model_name,
        transactionImport: parsed.transaction_import,
        mimeType: parsed.mime_type,
        sizeBytes: parsed.size_bytes,
        parts: parsed.parts.map((part) => ({
            id: part.id,
            position: part.position,
            originalFilename: part.original_filename,
            mimeType: part.mime_type,
            sizeBytes: part.size_bytes,
            status: part.status,
            rejectionReason: part.rejection_reason,
        })),
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
    type: DocumentType | null;
    status: DocumentStatus;
    stage: DocumentStage;
    rejectionReason: RejectionReason | null;
    mimeType: string | null;
    sizeBytes: number;
    /** How many files this document is made of — 1 for almost all of them. */
    partCount: number;
    /** The name the newest reading proposed, as the model wrote it — null when none did. */
    proposedName: string | null;
    source: DocumentSource;
    receivedAt: string;
}

const documentListItemValidator = vObject({
    id: vString,
    filename: vString,
    type: vNullable(vEnum(DOCUMENT_TYPES)),
    status: vEnum(DOCUMENT_STATUSES),
    stage: vEnum(DOCUMENT_STAGES),
    rejection_reason: vNullable(vEnum(REJECTION_REASONS)),
    mime_type: vNullable(vString),
    size_bytes: vNumber,
    part_count: vNumber,
    proposed_name: vNullable(vString),
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
                partCount: parsed.part_count,
                proposedName: parsed.proposed_name,
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
/** Which way money moves for the filing subject — derived here, never asked of a model. */
export const PAYMENT_DIRECTIONS = ['IN', 'OUT'] as const;

export type PaymentDirection = (typeof PAYMENT_DIRECTIONS)[number];

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
    /** Which reader produced this, and which version — a parser name, or a model. */
    extractorVersion: string;
    modelProvider: string | null;
    modelName: string | null;
    /** What the reading cost. Null where nobody measured it — a parser, or a quiet provider. */
    durationMs: number | null;
    promptTokens: number | null;
    completionTokens: number | null;
    schemaVersion: string;
    /** A decimal string (e.g. `"0.9500"`), never parsed to a lossy float for storage — only
     * converted to a number transiently for display (`Number(confidence)` in a percentage bar).
     *
     * Null where the reader reported none — a parser has no opinion, and a model that omitted the
     * field measured nothing. A placeholder here would be drawn as a real assessment. */
    confidence: string | null;
    /**
     * Which side of each payment this subject is on, in payment order.
     *
     * Derived on the server against the subject's own name and fiscal identifier — never asked of
     * the model, which cannot see who is filing. Kept beside `proposedFields` rather than mixed
     * into it: the reading is a record of the document and stays true whoever reads it, while this
     * is what it means for one taxpayer. Both null where neither party is them — a dividend
     * decision names every associate and only one of them is filing.
     */
    paymentParties: { direction: PaymentDirection | null; counterparty: string | null }[];
    status: ExtractionStatus;
    reviewedBy: string | null;
    proposedFields: Record<string, unknown>;
    createdAt: string;
    updatedAt: string;
}

const documentExtractionSummaryValidator = vObject({
    id: vString,
    extractor_type: vEnum(EXTRACTOR_TYPES),
    extractor_version: vString,
    model_provider: vNullable(vString),
    model_name: vNullable(vString),
    duration_ms: vNullable(vNumber),
    prompt_tokens: vNullable(vNumber),
    completion_tokens: vNullable(vNumber),
    schema_version: vString,
    confidence: vNullable(vString),
    payment_parties: vArray(
        vObject({
            direction: vNullable(vEnum(PAYMENT_DIRECTIONS)),
            counterparty: vNullable(vString),
        }),
    ),
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
        extractorVersion: parsed.extractor_version,
        modelProvider: parsed.model_provider,
        modelName: parsed.model_name,
        durationMs: parsed.duration_ms,
        promptTokens: parsed.prompt_tokens,
        completionTokens: parsed.completion_tokens,
        schemaVersion: parsed.schema_version,
        confidence: parsed.confidence,
        paymentParties: parsed.payment_parties,
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

/**
 * What to call a document's type on screen.
 *
 * Null until something has read the document — the type is decided by the reading, not by a picker
 * at upload — and every screen has to say that the same way.
 */
export function documentTypeLabel(
    type: DocumentType | null,
    t: (pick: (d: { documentType: Record<string, string>; documentTypeUnknown: string }) => string) => string,
): string {
    return type === null ? t((d) => d.documentTypeUnknown) : t((d) => d.documentType[type] ?? '');
}
