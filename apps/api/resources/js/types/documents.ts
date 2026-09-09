import {
    type CursorPage,
    parseJson,
    vCursorPage,
    vEnum,
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

/** `App\Http\Controllers\Api\V1\Documents\ShowDocumentController` response — metadata only. */
export interface DocumentSummary {
    id: string;
    type: DocumentType;
    status: DocumentStatus;
    rejectionReason: RejectionReason | null;
    mimeType: string;
    sizeBytes: number;
    receivedAt: string;
}

export function parseDocumentSummary(text: string): DocumentSummary {
    const parsed = parseJson(
        text,
        vObject({
            id: vString,
            type: vEnum(DOCUMENT_TYPES),
            status: vEnum(DOCUMENT_STATUSES),
            rejection_reason: vNullable(vEnum(REJECTION_REASONS)),
            mime_type: vString,
            size_bytes: vNumber,
            received_at: vString,
        }),
    );

    return {
        id: parsed.id,
        type: parsed.type,
        status: parsed.status,
        rejectionReason: parsed.rejection_reason,
        mimeType: parsed.mime_type,
        sizeBytes: parsed.size_bytes,
        receivedAt: parsed.received_at,
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
export const EXTRACTOR_TYPES = ['NATIVE_PDF_TEXT', 'EXTERNAL_DOCUMENT_AI', 'HUMAN_CORRECTION'] as const;

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
