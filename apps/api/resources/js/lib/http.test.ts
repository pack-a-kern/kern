import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiRequest, apiRequestBinary } from './http';

function setCsrfMeta(token: string): void {
    document.head.querySelectorAll('meta[name="csrf-token"]').forEach((el) => {
        el.remove();
    });
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', token);
    document.head.append(meta);
}

describe('apiRequest', () => {
    beforeEach(() => {
        setCsrfMeta('test-token');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sends the CSRF header on a mutating request but not on GET', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockImplementation(() => Promise.resolve(new Response('{"ok":true}', { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);

        await apiRequest('/v1/auth/login', { method: 'POST', body: { email: 'a@b.com', password: 'x' } });
        const postHeaders = fetchMock.mock.calls[0]?.[1]?.headers as Record<string, string> | undefined;
        expect(postHeaders?.['X-CSRF-TOKEN']).toBe('test-token');

        await apiRequest('/v1/auth/mfa/status');
        const getHeaders = fetchMock.mock.calls[1]?.[1]?.headers as Record<string, string> | undefined;
        expect(getHeaders?.['X-CSRF-TOKEN']).toBeUndefined();
    });

    it('resolves to the raw response text on success', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"hello":"world"}', { status: 200 })));

        const text = await apiRequest('/v1/auth/mfa/status');
        expect(JSON.parse(text)).toEqual({ hello: 'world' });
    });

    it('throws ApiError with the server message and field errors on a 422', async () => {
        const body = JSON.stringify({
            message: 'The given data was invalid.',
            errors: { email: ['Email deja folosit.'] },
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 422 })));

        await expect(apiRequest('/v1/auth/register', { method: 'POST', body: {} })).rejects.toMatchObject({
            status: 422,
            errors: { email: ['Email deja folosit.'] },
        });
    });

    it('flags a 429 as rate limited', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"message":"Too many"}', { status: 429 })));

        try {
            await apiRequest('/v1/auth/login', { method: 'POST', body: {} });
            expect.fail('expected apiRequest to throw');
        } catch (error) {
            expect(error).toBeInstanceOf(ApiError);
            expect((error as ApiError).isRateLimited).toBe(true);
        }
    });

    it('wraps a network failure in an ApiError instead of letting it propagate raw', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        await expect(apiRequest('/v1/auth/mfa/status')).rejects.toBeInstanceOf(ApiError);
    });

    it('falls back to a generic message when a non-2xx body is not JSON', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>502</html>', { status: 502 })));

        await expect(apiRequest('/v1/auth/mfa/status')).rejects.toMatchObject({ status: 502 });
    });
});

describe('apiRequestBinary', () => {
    beforeEach(() => {
        setCsrfMeta('test-token');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('resolves to a Blob with the response content-type on success', async () => {
        const bytes = new Uint8Array([1, 2, 3, 4]);
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    new Response(bytes, { status: 200, headers: { 'Content-Type': 'application/pdf' } }),
                ),
        );

        const blob = await apiRequestBinary('/v1/subjects/s1/documents/d1/content');
        expect(blob.type).toBe('application/pdf');
        expect(blob.size).toBe(4);
    });

    it('throws the same typed ApiError shape as apiRequest on a non-2xx response', async () => {
        const body = JSON.stringify({ message: 'not available', reason: 'CONTENT_NOT_AVAILABLE' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 409 })));

        await expect(apiRequestBinary('/v1/subjects/s1/documents/d1/content')).rejects.toMatchObject({
            status: 409,
            reason: 'CONTENT_NOT_AVAILABLE',
        });
    });

    it('never sends a CSRF header for a GET', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockResolvedValue(new Response(new Uint8Array([1]), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        await apiRequestBinary('/v1/subjects/s1/documents/d1/content');
        const headers = fetchMock.mock.calls[0]?.[1]?.headers as Record<string, string> | undefined;
        expect(headers?.['X-CSRF-TOKEN']).toBeUndefined();
    });
});
