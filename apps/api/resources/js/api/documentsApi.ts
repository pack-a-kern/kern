import { ApiError, apiRequest, apiRequestBinary, apiUpload } from '../lib/http';
import { type NumberedPage, type CursorPage, parseJson, vBoolean, vObject, vString } from '../lib/validate';
import {
    type AcceptedExtraction,
    type DocumentListItem,
    type CorrectedExtraction,
    type DocumentExtractionSummary,
    type DocumentStage,
    type DocumentHistory,
    type DocumentSummary,
    type DocumentType,
    type UploadAccepted,
    parseAcceptedExtraction,
    parseCorrectedExtraction,
    parseDocumentExtractionsPage,
    parseDocumentHistory,
    parseDocumentSummary,
    parseDocumentsPage,
    parseUploadAccepted,
} from '../types/documents';

/**
 * Thrown when `App\Domain\Documents\FileSecurity\FileSecurityValidator` rejects a file
 * synchronously (`422`, before any `documents` row exists) — `reason` is the closed
 * `RejectionReason` value from `docs/handoff/022-document-pipeline.md`. Distinguished from a
 * generic {@link ApiError} so the capture UI can show the specific, translated rejection reason
 * rather than a raw HTTP-error message.
 */
export class DocumentRejectedError extends Error {
    constructor(
        public readonly reason: string,
        message: string,
    ) {
        super(message);
        this.name = 'DocumentRejectedError';
    }
}

/**
 * `POST /api/v1/subjects/{subject}/documents` — multipart upload. `BLUEPRINT.md` §14.1's "upload
 * ticket" step; a `202` means the document exists and entered `QUARANTINE`, not that processing
 * finished — the caller follows up over the Reverb `tax-subjects.{subject}.documents` channel.
 */
export async function uploadDocument(
    subjectId: string,
    type: DocumentType,
    file: File | Blob,
    filename: string,
    onProgress?: (fraction: number) => void,
): Promise<UploadAccepted> {
    const form = new FormData();
    form.set('type', type);
    form.set('file', file, filename);

    try {
        const text = await apiUpload(`/v1/subjects/${encodeURIComponent(subjectId)}/documents`, form, onProgress);

        return parseUploadAccepted(text);
    } catch (error) {
        if (error instanceof ApiError && error.status === 422 && error.reason) {
            throw new DocumentRejectedError(error.reason, error.message);
        }

        // The subject already holds these exact bytes. Not a failure to retry — retrying is what
        // produced it — so it gets its own type rather than being lumped in with transport errors.
        if (error instanceof ApiError && error.status === 409 && error.reason === 'DUPLICATE_DOCUMENT') {
            throw new DocumentDuplicateError(error.message);
        }

        throw error;
    }
}

/**
 * The subject already has a byte-identical document.
 *
 * Identity is the content hash, not the filename — see
 * `App\Domain\Documents\Exceptions\DuplicateDocumentException`.
 */
export class DocumentDuplicateError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'DocumentDuplicateError';
    }
}

/** Filters the inbox offers. Every one is a column stored in clear; see {@link listDocuments}. */
export interface DocumentListFilters {
    /** The stage a person sees — the same vocabulary the list's own column uses. */
    stage?: DocumentStage;
    type?: DocumentType;
    receivedFrom?: string;
    receivedTo?: string;
    needsReview?: boolean;
}

/**
 * `GET /api/v1/subjects/{subject}/documents` — this subject's documents, newest first.
 *
 * The list comes from the server, not from this device's IndexedDB. That cache still exists and
 * still earns its keep offline, but it only ever held what *this* browser uploaded — so a document
 * captured on a phone was invisible on a laptop, and clearing site data emptied the screen while
 * the documents themselves sat safely in the object store.
 *
 * There is no filename search: the column is encrypted with a random nonce and so is not
 * searchable. Filtering is on the metadata stored in clear.
 */
export async function listDocuments(
    subjectId: string,
    filters: DocumentListFilters = {},
    page = 1,
    perPage?: number,
): Promise<NumberedPage<DocumentListItem>> {
    const query = new URLSearchParams();

    if (filters.stage !== undefined) {
        query.set('stage', filters.stage);
    }

    if (filters.type !== undefined) {
        query.set('type', filters.type);
    }

    if (filters.receivedFrom !== undefined && filters.receivedFrom !== '') {
        query.set('received_from', filters.receivedFrom);
    }

    if (filters.receivedTo !== undefined && filters.receivedTo !== '') {
        query.set('received_to', filters.receivedTo);
    }

    if (filters.needsReview === true) {
        query.set('needs_review', '1');
    }

    if (page > 1) {
        query.set('page', String(page));
    }

    if (perPage !== undefined) {
        query.set('per_page', String(perPage));
    }

    const suffix = query.size === 0 ? '' : `?${query.toString()}`;

    return parseDocumentsPage(await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/documents${suffix}`));
}

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}` — the authorised-HTTPS re-fetch `BLUEPRINT.md`
 * §16 requires after any Reverb progress event. Never trust the broadcast payload as the final
 * state; always confirm it here.
 */
export async function fetchDocument(subjectId: string, documentId: string): Promise<DocumentSummary> {
    const text = await apiRequest(
        `/v1/subjects/${encodeURIComponent(subjectId)}/documents/${encodeURIComponent(documentId)}`,
    );

    return parseDocumentSummary(text);
}

/**
 * `GET .../documents/{id}/history` — the virus-scan records and this document's own audit-chain
 * entries.
 *
 * Subject-scoped, unlike the `/v1/audit` screens that span every tax subject and sit behind an
 * administrator permission. Being unable to answer "was my document scanned?" without an
 * administrator is not a security property.
 */
export async function fetchDocumentHistory(subjectId: string, documentId: string): Promise<DocumentHistory> {
    return parseDocumentHistory(
        await apiRequest(
            `/v1/subjects/${encodeURIComponent(subjectId)}/documents/${encodeURIComponent(documentId)}/history`,
        ),
    );
}

/** Fields the "create transaction from this document" form sends. */
export interface CreateTransactionFromDocumentRequest {
    account_id: string;
    /** Signed minor units — the sign *is* the direction, so out is negative and in is positive. */
    amount_minor: number;
    currency_code: string;
    booked_at: string;
    description: string;
    counterparty?: string;
    /** Which reading of the document was trusted, when one was. */
    document_extraction_id?: string;
}

/**
 * `POST .../documents/{id}/transaction` — turn a document into a wallet transaction that keeps it
 * as evidence.
 *
 * The figures travel in the request rather than being re-derived server-side from the extraction,
 * so what reaches the ledger is what the person actually saw and agreed to. A document no parser
 * could read becomes a transaction the same way — they type what they see, and the document is
 * attached identically.
 */
export async function createTransactionFromDocument(
    subjectId: string,
    documentId: string,
    body: CreateTransactionFromDocumentRequest,
): Promise<void> {
    await apiRequest(
        `/v1/subjects/${encodeURIComponent(subjectId)}/documents/${encodeURIComponent(documentId)}/transaction`,
        { method: 'POST', body: { ...body } },
    );
}

/**
 * `DELETE .../documents/{id}` — remove a document, its derivatives and the encrypted bytes.
 *
 * A transaction built from it keeps its own row with a null document link: the figure was real, and
 * losing its evidence is a loss of provenance rather than a reason to change someone's income.
 */
export async function deleteDocument(subjectId: string, documentId: string): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/documents/${encodeURIComponent(documentId)}`, {
        method: 'DELETE',
    });
}

export async function grantDocumentAiConsent(subjectId: string): Promise<boolean> {
    const text = await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/document-ai-consent`, {
        method: 'POST',
    });

    return parseJson(text, vObject({ consented: vBoolean })).consented;
}

export async function revokeDocumentAiConsent(subjectId: string): Promise<boolean> {
    const text = await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/document-ai-consent`, {
        method: 'DELETE',
    });

    return parseJson(text, vObject({ consented: vBoolean })).consented;
}

/**
 * Thrown when accepting or correcting an extraction that is no longer `PROPOSED`
 * (`409 {"reason": "ALREADY_REVIEWED"}`) — e.g. a second browser tab/device reviewed it first.
 */
export class ExtractionAlreadyReviewedError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'ExtractionAlreadyReviewedError';
    }
}

function rethrowAlreadyReviewed(error: unknown): never {
    if (error instanceof ApiError && error.status === 409 && error.reason === 'ALREADY_REVIEWED') {
        throw new ExtractionAlreadyReviewedError(error.message);
    }

    throw error;
}

function documentExtractionsPath(subjectId: string, documentId: string): string {
    return `/v1/subjects/${encodeURIComponent(subjectId)}/documents/${encodeURIComponent(documentId)}/extractions`;
}

/**
 * `GET .../documents/{document}/extractions` — every proposal/correction attempt for this
 * document, `BLUEPRINT.md` §14.6's human review queue. Includes rows already reviewed (`ACCEPTED`/
 * `REJECTED`), not only `PROPOSED` ones — that is what makes the audit trail (what the AI
 * proposed, what a human decided) visible rather than hidden.
 */
export async function fetchDocumentExtractions(
    subjectId: string,
    documentId: string,
    cursor?: string,
): Promise<CursorPage<DocumentExtractionSummary>> {
    const query = cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
    const text = await apiRequest(`${documentExtractionsPath(subjectId, documentId)}${query}`);

    return parseDocumentExtractionsPage(text);
}

/**
 * `POST .../extractions/{extraction}/accept` — a human accepting the AI's proposal exactly as
 * written. Never called automatically (`BLUEPRINT.md` §14.6); always the direct result of a user
 * clicking "Acceptă".
 */
/**
 * `POST .../extractions/retry` — run the reading step again for a document that produced no
 * proposal.
 *
 * Returns nothing: the reading happens on the queue, and the outcome arrives over the documents
 * channel as `DocumentAiExtractionFinished` or `DocumentExtractionProposed`. A 409 from the server
 * (already proposed, or not ready) surfaces as an `ApiError` for the caller to show.
 */
export async function retryDocumentExtraction(subjectId: string, documentId: string): Promise<void> {
    await apiRequest(`${documentExtractionsPath(subjectId, documentId)}/retry`, { method: 'POST' });
}

/**
 * `PATCH .../documents/{document}` — name a document, or clear the name with `null` to return it to
 * its hash-derived one. Resolves to whatever it is now called.
 */
export async function renameDocument(
    subjectId: string,
    documentId: string,
    displayName: string | null,
): Promise<string> {
    const text = await apiRequest(
        `/v1/subjects/${encodeURIComponent(subjectId)}/documents/${encodeURIComponent(documentId)}`,
        { method: 'PATCH', body: { display_name: displayName } },
    );

    return parseRenamedDocument(text);
}

function parseRenamedDocument(text: string): string {
    return parseJson(text, vObject({ id: vString, filename: vString })).filename;
}

export async function acceptDocumentExtraction(
    subjectId: string,
    documentId: string,
    extractionId: string,
): Promise<AcceptedExtraction> {
    try {
        const text = await apiRequest(
            `${documentExtractionsPath(subjectId, documentId)}/${encodeURIComponent(extractionId)}/accept`,
            {
                method: 'POST',
            },
        );

        return parseAcceptedExtraction(text);
    } catch (error) {
        rethrowAlreadyReviewed(error);
    }
}

/**
 * `POST .../extractions/{extraction}/correct` — a human supplying the correct value(s) instead of
 * the AI's proposal. This never overwrites the original proposal server-side: it stays exactly as
 * proposed, marked `REJECTED`, while a brand-new `HUMAN_CORRECTION` row (this call's return value)
 * holds the correction, already `ACCEPTED` (`docs/handoff/031-pwa-backend-endpoints.md` Key
 * decision #2). `fields` must be non-empty.
 */
export async function correctDocumentExtraction(
    subjectId: string,
    documentId: string,
    extractionId: string,
    fields: Record<string, unknown>,
): Promise<CorrectedExtraction> {
    try {
        const text = await apiRequest(
            `${documentExtractionsPath(subjectId, documentId)}/${encodeURIComponent(extractionId)}/correct`,
            {
                method: 'POST',
                body: { fields },
            },
        );

        return parseCorrectedExtraction(text);
    } catch (error) {
        rethrowAlreadyReviewed(error);
    }
}

/**
 * Thrown by {@link fetchDocumentContent} when `documents.status` is `QUARANTINE`/`REJECTED`
 * (`409 {"reason": "CONTENT_NOT_AVAILABLE"}`) — the original bytes have not been promoted out of
 * quarantine yet, or never will be.
 */
export class DocumentContentNotAvailableError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'DocumentContentNotAvailableError';
    }
}

/**
 * `GET .../documents/{document}/content` — the decrypted original bytes, streamed. There is no
 * signed/temporary URL for this (`docs/handoff/031-pwa-backend-endpoints.md` Key decision #4):
 * every call re-checks the caller's session and active membership, so the returned `Blob` must be
 * held in memory (an `URL.createObjectURL()` the caller revokes when done) — never written to
 * `localStorage`/IndexedDB (`BLUEPRINT.md` §17: decrypted documents are never persisted client-side).
 */
export async function fetchDocumentContent(subjectId: string, documentId: string): Promise<Blob> {
    try {
        return await apiRequestBinary(
            `/v1/subjects/${encodeURIComponent(subjectId)}/documents/${encodeURIComponent(documentId)}/content`,
        );
    } catch (error) {
        if (error instanceof ApiError && error.status === 409 && error.reason === 'CONTENT_NOT_AVAILABLE') {
            throw new DocumentContentNotAvailableError(error.message);
        }

        throw error;
    }
}
