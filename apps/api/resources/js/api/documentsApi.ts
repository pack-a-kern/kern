import { ApiError, apiRequest, apiRequestBinary } from '../lib/http';
import { type CursorPage, parseJson, vBoolean, vObject } from '../lib/validate';
import {
    type AcceptedExtraction,
    type CorrectedExtraction,
    type DocumentExtractionSummary,
    type DocumentSummary,
    type DocumentType,
    type UploadAccepted,
    parseAcceptedExtraction,
    parseCorrectedExtraction,
    parseDocumentExtractionsPage,
    parseDocumentSummary,
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
): Promise<UploadAccepted> {
    const form = new FormData();
    form.set('type', type);
    form.set('file', file, filename);

    try {
        const text = await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/documents`, {
            method: 'POST',
            body: form,
        });

        return parseUploadAccepted(text);
    } catch (error) {
        if (error instanceof ApiError && error.status === 422 && error.reason) {
            throw new DocumentRejectedError(error.reason, error.message);
        }

        throw error;
    }
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
