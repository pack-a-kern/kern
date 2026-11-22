import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    fetchAiModels,
    getAiTaskModels,
    listAiProviders,
    listSettings,
    replaceAiConnection,
    testAiConnection,
    updateSettingsGroup,
    verifyAiModels,
} from './settingsApi';

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
        mockFetch({ success: true, latency_ms: 12 });

        await expect(testAiConnection()).resolves.toEqual({ success: true, error: null, latencyMs: 12 });

        // A failure carries no reason — that is in the server log.
        mockFetch({ success: false, error: 'failed' });

        await expect(testAiConnection()).resolves.toEqual({ success: false, error: 'failed', latencyMs: null });
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

    it('asks for models by provider alone, never sending an address or a key', async () => {
        // The server lists from the saved address with its saved key; sending either was how the
        // stored key used to reach an address nobody saved it with.
        const fetchMock = mockFetch({ ok: true, models: ['gpt-4o'], reason: null });

        await fetchAiModels('openai');

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('/api/v1/settings/ai/models');
        expect(init.method).toBe('POST');
        expect(jsonBody(init)).toEqual({ provider: 'openai' });
    });

    it('reports a failed listing as a value rather than throwing', async () => {
        mockFetch({ ok: false, models: [], reason: 'failed' });

        await expect(fetchAiModels('openai')).resolves.toEqual({ ok: false, models: [], reason: 'failed' });
    });

    it('reads the per-task models from the GET route, no body', async () => {
        const fetchMock = mockFetch({
            tasks: [
                { id: 'document_read', model: 'base-model', uses_default: true },
                { id: 'statement_classification', model: 'cls-model', uses_default: false },
                { id: 'law_second_reader', model: null, uses_default: false },
            ],
        });

        await expect(getAiTaskModels()).resolves.toEqual([
            { id: 'document_read', model: 'base-model', usesDefault: true },
            { id: 'statement_classification', model: 'cls-model', usesDefault: false },
            { id: 'law_second_reader', model: null, usesDefault: false },
        ]);

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/api/v1/settings/ai/task-models');
        expect(init.method ?? 'GET').toBe('GET');
    });

    it('verifies models through the POST route and keeps the exists map', async () => {
        const fetchMock = mockFetch({
            checkable: true,
            reason: null,
            exists: { 'base-model': true, 'cls-model': false },
        });

        await expect(verifyAiModels()).resolves.toEqual({
            checkable: true,
            reason: null,
            exists: { 'base-model': true, 'cls-model': false },
        });

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/api/v1/settings/ai/task-models/verify');
        expect(init.method).toBe('POST');
    });

    it('reports an uncheckable verification as a value rather than throwing', async () => {
        mockFetch({ checkable: false, reason: 'not_configured', exists: {} });

        await expect(verifyAiModels()).resolves.toEqual({
            checkable: false,
            reason: 'not_configured',
            exists: {},
        });
    });

    it('saves the address and the key together, an empty key as none', async () => {
        const fetchMock = mockFetch({});

        await replaceAiConnection('http://10.73.4.5:11434', '');

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('/api/v1/settings/ai/connection');
        expect(init.method).toBe('PUT');
        expect(jsonBody(init)).toEqual({ base_url: 'http://10.73.4.5:11434', api_key: null });
    });

    it('rejects a catalogue response that is not shaped like one', async () => {
        mockFetch({ providers: [{ label: 'Missing its key' }] });

        await expect(listAiProviders()).rejects.toThrow(TypeError);
    });
});
