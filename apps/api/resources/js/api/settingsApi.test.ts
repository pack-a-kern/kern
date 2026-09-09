import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { fetchAiModels, listAiProviders, listSettings, testAiConnection, updateSettingsGroup } from './settingsApi';

/**
 * `apiRequest` already prefixes `/api`, so a client passing `/api/v1/...` produces
 * `/api/api/v1/...` and a 404 — which is exactly what shipped and had to be caught in a browser.
 * These assert the request URL, not just the parsed result, so the mistake cannot return silently.
 */
function mockFetch(payload: unknown, status = 200): ReturnType<typeof vi.fn> {
    // A fresh Response per call: a body can only be read once, and updateSettingsGroup makes two
    // requests (the PUT, then a re-read so provenance badges cannot drift stale after a save).
    const fetchMock = vi.fn().mockImplementation(() =>
        Promise.resolve(
            new Response(JSON.stringify(payload), {
                status,
                headers: { 'content-type': 'application/json' },
            }),
        ),
    );
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

/**
 * `RequestInit['body']` is a broad union; every request this module makes sends a JSON string, so
 * this narrows it once rather than stringifying a possibly-non-string value at each call site.
 */
function jsonBody(init: RequestInit): unknown {
    expect(typeof init.body).toBe('string');

    return JSON.parse(init.body as string);
}

/** Mutating requests read the CSRF token from a meta tag, exactly as the real page does. */
beforeEach(() => {
    document.head.querySelectorAll('meta[name="csrf-token"]').forEach((el) => {
        el.remove();
    });
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'test-token');
    document.head.append(meta);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const emptyGroups = { groups: [{ group: 'spv', settings: [] }] };

describe('settings API paths', () => {
    it('requests /api/v1/settings exactly once, without a doubled prefix', async () => {
        const fetchMock = mockFetch(emptyGroups);

        await listSettings();

        const url = String(fetchMock.mock.calls[0]?.[0]);
        expect(url).toContain('/api/v1/settings');
        expect(url).not.toContain('/api/api/');
    });

    it('builds a group update URL without a doubled prefix', async () => {
        const fetchMock = mockFetch(emptyGroups);

        await updateSettingsGroup('spv', { 'spv.messages_window_days': 90 });

        const url = String(fetchMock.mock.calls[0]?.[0]);
        expect(url).toContain('/api/v1/settings/group/spv');
        expect(url).not.toContain('/api/api/');
    });

    it('builds the connection-test URL without a doubled prefix', async () => {
        const fetchMock = mockFetch({ success: true });

        await testAiConnection();

        const url = String(fetchMock.mock.calls[0]?.[0]);
        expect(url).toContain('/api/v1/settings/ai/test-connection');
        expect(url).not.toContain('/api/api/');
    });

    it('reads the connection result from the real ConnectionTestResult shape', async () => {
        mockFetch({ success: false, error: 'authentication_failed', message: 'Bad key', latency_ms: 12 });

        await expect(testAiConnection()).resolves.toEqual({
            success: false,
            error: 'authentication_failed',
            message: 'Bad key',
            latencyMs: 12,
        });
    });
});

describe('AI provider catalogue and model discovery', () => {
    it('reads the preset catalogue from the API rather than a bundled constant', async () => {
        const fetchMock = mockFetch({
            providers: [
                {
                    key: 'openai',
                    label: 'OpenAI',
                    base_url: 'https://api.openai.com',
                    requires_api_key: true,
                    can_list_models: true,
                    models: ['gpt-4o'],
                    docs_url: 'https://platform.openai.com/docs/api-reference',
                },
            ],
        });

        const providers = await listAiProviders();

        expect(providers).toEqual([
            {
                key: 'openai',
                label: 'OpenAI',
                baseUrl: 'https://api.openai.com',
                requiresApiKey: true,
                canListModels: true,
                models: ['gpt-4o'],
                docsUrl: 'https://platform.openai.com/docs/api-reference',
            },
        ]);

        const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/api/v1/settings/ai/providers');
    });

    it('sends a draft API key in the body, never in the URL', async () => {
        const fetchMock = mockFetch({ ok: true, models: ['gpt-4o'], reason: null });

        await fetchAiModels({ provider: 'openai', baseUrl: 'https://api.openai.com', apiKey: 'sk-secret' });

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('/api/v1/settings/ai/models');
        expect(url).not.toContain('sk-secret');
        expect(init.method).toBe('POST');
        expect(jsonBody(init)).toEqual({
            provider: 'openai',
            base_url: 'https://api.openai.com',
            api_key: 'sk-secret',
        });
    });

    it('omits blank draft fields so the server falls back to the saved configuration', async () => {
        const fetchMock = mockFetch({ ok: true, models: [], reason: null });

        await fetchAiModels({ provider: 'ollama', baseUrl: null, apiKey: null });

        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(jsonBody(init)).toEqual({ provider: 'ollama' });
    });

    it('reports a failed listing as a value rather than throwing', async () => {
        mockFetch({ ok: false, models: [], reason: 'authentication_failed' });

        await expect(fetchAiModels({ provider: 'openai', baseUrl: null, apiKey: null })).resolves.toEqual({
            ok: false,
            models: [],
            reason: 'authentication_failed',
        });
    });

    it('rejects a catalogue response that is not shaped like one', async () => {
        mockFetch({ providers: [{ label: 'Missing its key' }] });

        await expect(listAiProviders()).rejects.toThrow(TypeError);
    });
});
