import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiRequest, apiRequestBinary, setStepUpPrompt, StepUpCancelled } from './http';

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

/**
 * The step-up retry: what happens when the server says a sensitive act needs a fresh code.
 *
 * Covered here rather than in a component test because the behaviour is this module's — when it
 * prompts, how many times it retries, and what it does when nobody is listening.
 */
describe('step-up verification', () => {
    beforeEach(() => {
        setCsrfMeta('test-token');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        setStepUpPrompt(null);
    });

    function refusal(): Response {
        return new Response(JSON.stringify({ message: 'needs a code', reason: 'VERIFICATION_REQUIRED' }), {
            status: 403,
        });
    }

    it('prompts, then sends the same request again once verified', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock
            .mockImplementationOnce(() => Promise.resolve(refusal()))
            .mockImplementationOnce(() => Promise.resolve(new Response('{"ok":true}', { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);

        const prompt = vi.fn(() => Promise.resolve(true));
        setStepUpPrompt(prompt);

        const body = await apiRequest('/v1/subjects/s/spv/connections', { method: 'POST', body: { a: 1 } });

        expect(prompt).toHaveBeenCalledOnce();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(body).toBe('{"ok":true}');
        // The same request, not a different one — a retry that dropped the body would succeed while
        // doing nothing.
        expect(fetchMock.mock.calls[1]?.[1]?.body).toBe(fetchMock.mock.calls[0]?.[1]?.body);
    });

    it('retries exactly once, so one click cannot become two writes', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockImplementation(() => Promise.resolve(refusal()));
        vi.stubGlobal('fetch', fetchMock);

        const prompt = vi.fn(() => Promise.resolve(true));
        setStepUpPrompt(prompt);

        await expect(apiRequest('/v1/x', { method: 'POST' })).rejects.toThrow(ApiError);

        expect(prompt).toHaveBeenCalledOnce();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    /*
     * A dismissed dialog is not a failure, and must not be reported as one.
     *
     * The refusal used to come straight back out, which left the calling screen's `catch` unable to
     * tell "they closed the password box" from "the server said no" — so cancelling "end this
     * session" announced that the session could not be ended, on all ten actions behind this gate.
     * `StepUpCancelled` is what makes the two distinguishable; `reportApiFailure` is what acts on it.
     */
    it('raises a cancellation, not the refusal, when the dialog is dismissed', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockImplementation(() => Promise.resolve(refusal()));
        vi.stubGlobal('fetch', fetchMock);

        setStepUpPrompt(() => Promise.resolve(false));

        await expect(apiRequest('/v1/x', { method: 'POST' })).rejects.toThrow(StepUpCancelled);
        // Not sent a second time: nothing was confirmed, so nothing may be done.
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    /*
     * With nothing on screen to answer the refusal, it *is* just a refusal — and the screen has to
     * hear about it, because the person is otherwise left clicking a button that silently does
     * nothing.
     */
    it('reports the refusal itself when no dialog is registered', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockImplementation(() => Promise.resolve(refusal()));
        vi.stubGlobal('fetch', fetchMock);

        await expect(apiRequest('/v1/x', { method: 'POST' })).rejects.toMatchObject({
            reason: 'VERIFICATION_REQUIRED',
        });
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('leaves another kind of 403 to the screen that asked', async () => {
        // A tenant refusal is also a 403. Prompting for a code there would ask somebody to prove who
        // they are in order to reach data that is not theirs, and then fail anyway.
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockImplementation(() =>
            Promise.resolve(
                new Response(JSON.stringify({ message: 'not yours', reason: 'TENANT_FORBIDDEN' }), {
                    status: 403,
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const prompt = vi.fn(() => Promise.resolve(true));
        setStepUpPrompt(prompt);

        await expect(apiRequest('/v1/x', { method: 'POST' })).rejects.toThrow(ApiError);

        expect(prompt).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('reports the refusal unchanged when no dialog is mounted at all', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockImplementation(() => Promise.resolve(refusal()));
        vi.stubGlobal('fetch', fetchMock);

        await expect(apiRequest('/v1/x', { method: 'POST' })).rejects.toThrow(ApiError);
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('covers a binary response too, which is a separate path through the same module', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock
            .mockImplementationOnce(() => Promise.resolve(refusal()))
            .mockImplementationOnce(() => Promise.resolve(new Response('pdf-bytes', { status: 200 })));
        vi.stubGlobal('fetch', fetchMock);

        setStepUpPrompt(() => Promise.resolve(true));

        const blob = await apiRequestBinary('/v1/x');

        expect(await blob.text()).toBe('pdf-bytes');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
