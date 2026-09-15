import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    DocumentContentNotAvailableError,
    ExtractionAlreadyReviewedError,
    acceptDocumentExtraction,
    correctDocumentExtraction,
    fetchDocumentContent,
    fetchDocumentExtractions,
} from './documentsApi';

function setCsrfMeta(token: string): void {
    document.head.querySelectorAll('meta[name="csrf-token"]').forEach((el) => {
        el.remove();
    });
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', token);
    document.head.append(meta);
}

beforeEach(() => {
    setCsrfMeta('test-token');
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('fetchDocumentExtractions', () => {
    it('requests the extractions path and parses the page', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        const body = JSON.stringify({
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
                    confidence: '0.9000',
                    payment_parties: [],
                    status: 'PROPOSED',
                    reviewed_by: null,
                    proposed_fields: { field: 'value' },
                    created_at: '2026-09-08T00:00:00+00:00',
                    updated_at: '2026-09-08T00:00:00+00:00',
                },
            ],
            next_cursor: null,
            prev_cursor: null,
        });
        fetchMock.mockResolvedValue(new Response(body, { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        const page = await fetchDocumentExtractions('subj-1', 'doc-1');

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/subjects/subj-1/documents/doc-1/extractions');
        expect(page.data[0]?.status).toBe('PROPOSED');
        expect(page.data[0]?.proposedFields).toEqual({ field: 'value' });
    });
});

describe('acceptDocumentExtraction', () => {
    it('posts to the accept endpoint and parses the result', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({
                    id: 'ext-1',
                    status: 'ACCEPTED',
                    reviewed_by: 'user-1',
                    reviewed_at: '2026-09-08T00:00:00+00:00',
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const result = await acceptDocumentExtraction('subj-1', 'doc-1', 'ext-1');

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/subjects/subj-1/documents/doc-1/extractions/ext-1/accept');
        expect(fetchMock.mock.calls[0]?.[1]?.method).toBe('POST');
        expect(result.status).toBe('ACCEPTED');
    });

    it('translates a 409 ALREADY_REVIEWED into ExtractionAlreadyReviewedError', async () => {
        const body = JSON.stringify({ message: 'Already reviewed.', reason: 'ALREADY_REVIEWED' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 409 })));

        await expect(acceptDocumentExtraction('subj-1', 'doc-1', 'ext-1')).rejects.toBeInstanceOf(
            ExtractionAlreadyReviewedError,
        );
    });
});

describe('correctDocumentExtraction', () => {
    it('sends the corrected fields and parses the new, superseding row', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({
                    id: 'ext-2',
                    status: 'ACCEPTED',
                    reviewed_by: 'user-1',
                    reviewed_at: '2026-09-08T00:00:00+00:00',
                    supersedes_extraction_id: 'ext-1',
                    proposed_fields: { field: 'corrected' },
                }),
                { status: 201 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const result = await correctDocumentExtraction('subj-1', 'doc-1', 'ext-1', { field: 'corrected' });

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/subjects/subj-1/documents/doc-1/extractions/ext-1/correct');
        const sentBody = JSON.parse(fetchMock.mock.calls[0]?.[1]?.body as string) as Record<string, unknown>;
        expect(sentBody).toEqual({ fields: { field: 'corrected' } });
        expect(result.supersedesExtractionId).toBe('ext-1');
        expect(result.id).not.toBe('ext-1');
    });

    it('translates a 409 ALREADY_REVIEWED into ExtractionAlreadyReviewedError', async () => {
        const body = JSON.stringify({ message: 'Already reviewed.', reason: 'ALREADY_REVIEWED' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 409 })));

        await expect(correctDocumentExtraction('subj-1', 'doc-1', 'ext-1', { a: 'b' })).rejects.toBeInstanceOf(
            ExtractionAlreadyReviewedError,
        );
    });
});

describe('fetchDocumentContent', () => {
    it('resolves to the decrypted bytes as a Blob', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(new Uint8Array([1, 2, 3]), {
                    status: 200,
                    headers: { 'Content-Type': 'application/pdf' },
                }),
            ),
        );

        const blob = await fetchDocumentContent('subj-1', 'doc-1');
        expect(blob.type).toBe('application/pdf');
    });

    it('translates a 409 CONTENT_NOT_AVAILABLE into DocumentContentNotAvailableError', async () => {
        const body = JSON.stringify({ message: 'Not ready.', reason: 'CONTENT_NOT_AVAILABLE' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 409 })));

        await expect(fetchDocumentContent('subj-1', 'doc-1')).rejects.toBeInstanceOf(DocumentContentNotAvailableError);
    });

    it('does not wrap an unrelated 404 as DocumentContentNotAvailableError', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Not found' }), { status: 404 })),
        );

        await expect(fetchDocumentContent('subj-1', 'doc-1')).rejects.not.toBeInstanceOf(
            DocumentContentNotAvailableError,
        );
    });
});
