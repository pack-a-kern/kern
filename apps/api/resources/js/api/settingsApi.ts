import { apiRequest } from '../lib/http';
import {
    type AiProvider,
    type ComponentHealth,
    type SettingGroupPayload,
    parseAiProviders,
    parseSettingGroups,
    parseSystemHealth,
} from '../types/settings';

/**
 * `GET /api/v1/settings` — every group with each setting's effective value and provenance.
 *
 * `apiRequest` resolves to the raw response *text*, not parsed JSON (see `lib/http.ts`), so every
 * caller parses it itself — the same shape `subjectsApi` uses.
 */
export async function listSettings(): Promise<SettingGroupPayload[]> {
    return parseSettingGroups(JSON.parse(await apiRequest('/v1/settings')));
}

/**
 * `PUT /api/v1/settings/{group}` — atomic for the group: if any value is rejected, none is applied.
 *
 * A `null` value clears an override and restores the inherited value; omitting a key leaves it
 * untouched. Those are different intents, so callers must pass null explicitly to clear.
 */
export async function updateSettingsGroup(
    group: string,
    settings: Record<string, string | number | boolean | null>,
): Promise<SettingGroupPayload[]> {
    // The PUT response carries only the updated group, so it is deliberately not used: re-reading
    // everything keeps provenance badges elsewhere from drifting stale after a save.
    await apiRequest(`/v1/settings/group/${group}`, { method: 'PUT', body: { settings } });

    return listSettings();
}

/**
 * `POST /api/v1/settings/ai/test-connection` — calls the configured provider for real.
 *
 * Response shape is `App\Domain\Settings\DataTransferObjects\ConnectionTestResult::toArray()`:
 * `success` always, plus `error`, `message`, `status` and `latency_ms` when present.
 */
export interface AiConnectionTest {
    success: boolean;
    error: string | null;
    message: string | null;
    latencyMs: number | null;
}

export async function testAiConnection(): Promise<AiConnectionTest> {
    const result: unknown = JSON.parse(await apiRequest('/v1/settings/ai/test-connection', { method: 'POST' }));

    if (typeof result !== 'object' || result === null) {
        throw new TypeError('Connection test returned an unexpected response.');
    }

    const candidate = result as Record<string, unknown>;

    return {
        success: candidate.success === true,
        error: typeof candidate.error === 'string' ? candidate.error : null,
        message: typeof candidate.message === 'string' ? candidate.message : null,
        latencyMs: typeof candidate.latency_ms === 'number' ? candidate.latency_ms : null,
    };
}

/**
 * `GET /api/v1/settings/ai/providers` — the preset catalogue.
 *
 * Fetched rather than bundled so adding a provider is a `config/ai_providers.php` edit with no
 * frontend rebuild.
 */
export async function listAiProviders(): Promise<AiProvider[]> {
    return parseAiProviders(JSON.parse(await apiRequest('/v1/settings/ai/providers')));
}

/**
 * `POST /api/v1/settings/ai/models` — asks the provider which models it serves.
 *
 * POST, not GET: the draft API key travels in the body so it never reaches a URL, an access log,
 * or browser history. Any omitted field falls back to the saved configuration server-side, so the
 * button also works for a provider whose key is stored and never sent back to the browser.
 *
 * Failure is a value, not a thrown error — "this provider cannot list models" is an ordinary
 * outcome, and the operator can always type a model name by hand.
 */
export interface AiModelList {
    ok: boolean;
    models: string[];
    reason: string | null;
}

export async function fetchAiModels(draft: {
    provider: string;
    baseUrl: string | null;
    apiKey: string | null;
}): Promise<AiModelList> {
    const body: Record<string, string> = { provider: draft.provider };

    if (draft.baseUrl !== null && draft.baseUrl !== '') {
        body.base_url = draft.baseUrl;
    }

    if (draft.apiKey !== null && draft.apiKey !== '') {
        body.api_key = draft.apiKey;
    }

    const result: unknown = JSON.parse(await apiRequest('/v1/settings/ai/models', { method: 'POST', body }));

    if (typeof result !== 'object' || result === null) {
        throw new TypeError('Model list returned an unexpected response.');
    }

    const candidate = result as Record<string, unknown>;

    return {
        ok: candidate.ok === true,
        models: Array.isArray(candidate.models)
            ? candidate.models.filter((model): model is string => typeof model === 'string')
            : [],
        reason: typeof candidate.reason === 'string' ? candidate.reason : null,
    };
}

/** `GET /api/v1/settings/health` — live state of the internal services this installation runs. */
export async function listSystemHealth(): Promise<ComponentHealth[]> {
    return parseSystemHealth(JSON.parse(await apiRequest('/v1/settings/health')));
}
