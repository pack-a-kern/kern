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
        filename: 'bon-fiscal.pdf',
        type: 'RECEIPT',
        status: 'READY',
        stage: 'ACTION_NEEDED',
        extraction_fields: { required: ['amount'], optional: ['currency'] },
        rejection_reason: null,
        original_filename: 'capture.PNG',
        ai_extraction_outcome: null,
        ai_extraction_attempted_at: null,
        ai_reply: null,
        ai_model_provider: null,
        ai_model_name: null,
        transaction_import: 'AWAITING_ACCEPTANCE',
        mime_type: 'application/pdf',
        size_bytes: 1024,
        parts: [
            {
                id: 'part-1',
                position: 1,
                original_filename: 'capture.PNG',
                mime_type: 'application/pdf',
                size_bytes: 1024,
                status: 'READY',
                rejection_reason: null,
            },
        ],
        received_at: '2026-09-01T10:00:00Z',
    });

    it('parses a well-formed ShowDocumentController response', () => {
        expect(parseDocumentSummary(validJson)).toEqual({
            id: 'doc-1',
            // Decrypted server-side for the subject that owns it — a review screen headed by a
            // UUID tells a reviewer nothing about what they are reviewing.
            filename: 'bon-fiscal.pdf',
            type: 'RECEIPT',
            status: 'READY',
            stage: 'ACTION_NEEDED',
            extractionFields: { required: ['amount'], optional: ['currency'] },
            rejectionReason: null,
            originalFilename: 'capture.PNG',
            aiExtractionOutcome: null,
            aiExtractionAttemptedAt: null,
            aiReply: null,
            aiModelProvider: null,
            aiModelName: null,
            transactionImport: 'AWAITING_ACCEPTANCE',
            mimeType: 'application/pdf',
            sizeBytes: 1024,
            parts: [
                {
                    id: 'part-1',
                    position: 1,
                    originalFilename: 'capture.PNG',
                    mimeType: 'application/pdf',
                    sizeBytes: 1024,
                    status: 'READY',
                    rejectionReason: null,
                },
            ],
            receivedAt: '2026-09-01T10:00:00Z',
        });
    });

    it('rejects an unknown status value rather than passing it through', () => {
        const bad = JSON.stringify({
            id: 'doc-1',
            type: 'RECEIPT',
            status: 'NOT_A_REAL_STATUS',
            rejection_reason: null,
            original_filename: 'capture.PNG',
            ai_extraction_outcome: null,
            ai_extraction_attempted_at: null,
            ai_reply: null,
            ai_model_provider: null,
            ai_model_name: null,
            transaction_import: 'AWAITING_ACCEPTANCE',
            mime_type: 'application/pdf',
            size_bytes: 1024,
            received_at: '2026-09-01T10:00:00Z',
        });

        expect(() => parseDocumentSummary(bad)).toThrow(ValidationError);
    });

    it('preserves a non-null rejection reason', () => {
        const rejected = JSON.stringify({
            id: 'doc-2',
            filename: 'factura.pdf',
            type: 'INVOICE',
            status: 'REJECTED',
            stage: 'REJECTED',
            extraction_fields: { required: ['amount'], optional: ['currency'] },
            rejection_reason: 'ENCRYPTED_PDF',
            original_filename: 'capture.PNG',
            ai_extraction_outcome: null,
            ai_extraction_attempted_at: null,
            ai_reply: null,
            ai_model_provider: null,
            ai_model_name: null,
            transaction_import: 'AWAITING_ACCEPTANCE',
            mime_type: 'application/pdf',
            size_bytes: 512,
            parts: [
                {
                    id: 'part-1',
                    position: 1,
                    original_filename: 'capture.PNG',
                    mime_type: 'application/pdf',
                    size_bytes: 512,
                    status: 'READY',
                    rejection_reason: null,
                },
            ],
            received_at: '2026-09-01T10:00:00Z',
        });

        expect(parseDocumentSummary(rejected).rejectionReason).toBe('ENCRYPTED_PDF');
    });

    it('preserves a recorded AI extraction outcome', () => {
        const failed = JSON.stringify({
            id: 'doc-3',
            filename: 'bon.png',
            type: 'RECEIPT',
            status: 'READY',
            stage: 'ACTION_NEEDED',
            extraction_fields: { required: ['amount'], optional: ['currency'] },
            rejection_reason: null,
            original_filename: 'capture.PNG',
            ai_extraction_outcome: 'FAILED_HTTP_STATUS',
            ai_extraction_attempted_at: '2026-09-10T16:01:51Z',
            ai_reply: null,
            ai_model_provider: null,
            ai_model_name: null,
            transaction_import: 'AWAITING_ACCEPTANCE',
            mime_type: 'image/png',
            size_bytes: 296440,
            parts: [
                {
                    id: 'part-1',
                    position: 1,
                    original_filename: 'capture.PNG',
                    mime_type: 'image/png',
                    size_bytes: 296440,
                    status: 'READY',
                    rejection_reason: null,
                },
            ],
            received_at: '2026-09-10T16:00:00Z',
        });

        const summary = parseDocumentSummary(failed);

        expect(summary.aiExtractionOutcome).toBe('FAILED_HTTP_STATUS');
        expect(summary.aiExtractionAttemptedAt).toBe('2026-09-10T16:01:51Z');
    });

    it('rejects an unknown AI extraction outcome rather than passing it through', () => {
        const bad = JSON.stringify({
            id: 'doc-4',
            filename: 'bon.png',
            type: 'RECEIPT',
            status: 'READY',
            stage: 'ACTION_NEEDED',
            extraction_fields: { required: ['amount'], optional: ['currency'] },
            rejection_reason: null,
            original_filename: 'capture.PNG',
            ai_extraction_outcome: 'NOT_A_REAL_OUTCOME',
            ai_extraction_attempted_at: null,
            ai_reply: null,
            ai_model_provider: null,
            ai_model_name: null,
            transaction_import: 'AWAITING_ACCEPTANCE',
            mime_type: 'image/png',
            size_bytes: 10,
            parts: [
                {
                    id: 'part-1',
                    position: 1,
                    original_filename: 'capture.PNG',
                    mime_type: 'image/png',
                    size_bytes: 10,
                    status: 'READY',
                    rejection_reason: null,
                },
            ],
            received_at: '2026-09-10T16:00:00Z',
        });

        expect(() => parseDocumentSummary(bad)).toThrow(ValidationError);
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
                extractor_version: '2026.09.0',
                model_provider: 'custom',
                model_name: 'qwen3-vl:8b-instruct-q4_K_M',
                duration_ms: 8400,
                prompt_tokens: 493,
                completion_tokens: 65,
                schema_version: '1.0.0',
                confidence: '0.9500',
                payment_parties: [],
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
                extractorVersion: '2026.09.0',
                modelProvider: 'custom',
                modelName: 'qwen3-vl:8b-instruct-q4_K_M',
                durationMs: 8400,
                promptTokens: 493,
                completionTokens: 65,
                schemaVersion: '1.0.0',
                confidence: '0.9500',
                paymentParties: [],
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
