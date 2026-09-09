import { describe, expect, it } from 'vitest';
import { ValidationError } from '../lib/validate';
import {
    parseAcceptedExtraction,
    parseCorrectedExtraction,
    parseDocumentExtractionsPage,
    parseDocumentLifecycleEvent,
    parseDocumentSummary,
    parseUploadAccepted,
} from './documents';

describe('parseDocumentSummary', () => {
    const validJson = JSON.stringify({
        id: 'doc-1',
        type: 'RECEIPT',
        status: 'READY',
        rejection_reason: null,
        mime_type: 'application/pdf',
        size_bytes: 1024,
        received_at: '2026-09-01T10:00:00Z',
    });

    it('parses a well-formed ShowDocumentController response', () => {
        expect(parseDocumentSummary(validJson)).toEqual({
            id: 'doc-1',
            type: 'RECEIPT',
            status: 'READY',
            rejectionReason: null,
            mimeType: 'application/pdf',
            sizeBytes: 1024,
            receivedAt: '2026-09-01T10:00:00Z',
        });
    });

    it('rejects an unknown status value rather than passing it through', () => {
        const bad = JSON.stringify({
            id: 'doc-1',
            type: 'RECEIPT',
            status: 'NOT_A_REAL_STATUS',
            rejection_reason: null,
            mime_type: 'application/pdf',
            size_bytes: 1024,
            received_at: '2026-09-01T10:00:00Z',
        });

        expect(() => parseDocumentSummary(bad)).toThrow(ValidationError);
    });

    it('preserves a non-null rejection reason', () => {
        const rejected = JSON.stringify({
            id: 'doc-2',
            type: 'INVOICE',
            status: 'REJECTED',
            rejection_reason: 'ENCRYPTED_PDF',
            mime_type: 'application/pdf',
            size_bytes: 512,
            received_at: '2026-09-01T10:00:00Z',
        });

        expect(parseDocumentSummary(rejected).rejectionReason).toBe('ENCRYPTED_PDF');
    });
});

describe('parseUploadAccepted', () => {
    it('parses the 202 body', () => {
        expect(parseUploadAccepted(JSON.stringify({ id: 'doc-1', status: 'QUARANTINE' }))).toEqual({
            id: 'doc-1',
            status: 'QUARANTINE',
        });
    });
});

describe('parseDocumentLifecycleEvent', () => {
    it('parses a minimal DocumentUploaded-shaped payload', () => {
        const event = parseDocumentLifecycleEvent({ document_id: 'doc-1', status: 'QUARANTINE', progress: 10 });

        expect(event).toEqual({
            documentId: 'doc-1',
            status: 'QUARANTINE',
            progress: 10,
            rejectionReason: null,
            needsOcr: null,
            extractionId: null,
            extractionStatus: null,
        });
    });

    it('reads the optional fields when present (DocumentExtractionProposed shape)', () => {
        const event = parseDocumentLifecycleEvent({
            document_id: 'doc-1',
            status: 'READY',
            progress: 90,
            extraction_id: 'ext-1',
            extraction_status: 'PROPOSED',
        });

        expect(event.extractionId).toBe('ext-1');
        expect(event.extractionStatus).toBe('PROPOSED');
    });

    it('throws on a payload missing the required fields', () => {
        expect(() => parseDocumentLifecycleEvent({ status: 'READY' })).toThrow(ValidationError);
    });
});

describe('parseDocumentExtractionsPage', () => {
    const validJson = JSON.stringify({
        data: [
            {
                id: 'ext-1',
                extractor_type: 'EXTERNAL_DOCUMENT_AI',
                schema_version: '1.0.0',
                confidence: '0.9500',
                status: 'PROPOSED',
                reviewed_by: null,
                proposed_fields: { invoice_number: 'INV-001', total_amount: '123.45' },
                created_at: '2026-09-08T12:00:00+00:00',
                updated_at: '2026-09-08T12:00:00+00:00',
            },
        ],
        path: 'http://example.test/api/v1/subjects/s/documents/d/extractions',
        per_page: 20,
        next_cursor: null,
        next_page_url: null,
        prev_cursor: null,
        prev_page_url: null,
    });

    it('parses a ListDocumentExtractionsController page, ignoring the pagination-only fields', () => {
        const page = parseDocumentExtractionsPage(validJson);

        expect(page.nextCursor).toBeNull();
        expect(page.data).toEqual([
            {
                id: 'ext-1',
                extractorType: 'EXTERNAL_DOCUMENT_AI',
                schemaVersion: '1.0.0',
                confidence: '0.9500',
                status: 'PROPOSED',
                reviewedBy: null,
                proposedFields: { invoice_number: 'INV-001', total_amount: '123.45' },
                createdAt: '2026-09-08T12:00:00+00:00',
                updatedAt: '2026-09-08T12:00:00+00:00',
            },
        ]);
    });

    it('does not assume any specific keys inside proposed_fields', () => {
        const raw = JSON.parse(validJson) as { data: [Record<string, unknown>] };
        raw.data[0]['proposed_fields'] = { anything: 'goes', nested: { a: 1 } };

        const page = parseDocumentExtractionsPage(JSON.stringify(raw));
        expect(page.data[0]?.proposedFields).toEqual({ anything: 'goes', nested: { a: 1 } });
    });

    it('rejects an unknown extractor_type', () => {
        const raw = JSON.parse(validJson) as { data: [Record<string, unknown>] };
        raw.data[0]['extractor_type'] = 'NOT_A_REAL_TYPE';

        expect(() => parseDocumentExtractionsPage(JSON.stringify(raw))).toThrow(ValidationError);
    });
});

describe('parseAcceptedExtraction', () => {
    it('parses the accept response', () => {
        expect(
            parseAcceptedExtraction(
                JSON.stringify({
                    id: 'ext-1',
                    status: 'ACCEPTED',
                    reviewed_by: 'user-1',
                    reviewed_at: '2026-09-08T12:05:00+00:00',
                }),
            ),
        ).toEqual({
            id: 'ext-1',
            status: 'ACCEPTED',
            reviewedBy: 'user-1',
            reviewedAt: '2026-09-08T12:05:00+00:00',
        });
    });
});

describe('parseCorrectedExtraction', () => {
    it('parses the correct response, preserving the id of the row it supersedes', () => {
        const corrected = parseCorrectedExtraction(
            JSON.stringify({
                id: 'ext-2',
                status: 'ACCEPTED',
                reviewed_by: 'user-1',
                reviewed_at: '2026-09-08T12:06:00+00:00',
                supersedes_extraction_id: 'ext-1',
                proposed_fields: { invoice_number: 'INV-999' },
            }),
        );

        expect(corrected).toEqual({
            id: 'ext-2',
            status: 'ACCEPTED',
            reviewedBy: 'user-1',
            reviewedAt: '2026-09-08T12:06:00+00:00',
            supersedesExtractionId: 'ext-1',
            proposedFields: { invoice_number: 'INV-999' },
        });
        // The original id is named explicitly, never inferred/guessed client-side — this is what
        // lets the review UI say "this replaces ext-1" without reconstructing it itself.
        expect(corrected.supersedesExtractionId).not.toBe(corrected.id);
    });
});
