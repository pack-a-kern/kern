import { apiRequest } from '../lib/http';
import {
    type AiProvider,
    type ComponentHealth,
    type SettingGroupPayload,
    parseAiProviders,
    parseSettingGroups,
    parseSystemHealth,
} from '../types/settings';
// Both live in the Operations types because the services that produce them do — the settings screen
// is where they are read, not where they are defined.
import { type PlatformData, parsePlatformData } from '../types/operations';

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
 * Response shape is `Kern\Platform\Settings\DataTransferObjects\ConnectionTestResult::toArray()`:
 * `success`, plus `latency_ms` on success or `error` — `not_configured` or `failed` — otherwise. Why
 * it failed is in the server log and deliberately not here: whoever presses the button chose the
 * address, and a reason would say whether anything answers at it.
 */
export interface AiConnectionTest {
    success: boolean;
    error: string | null;
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
 * `PUT /api/v1/settings/ai/connection` — the provider's address and its key, saved together.
 *
 * The only way either changes: a key only ever goes to the address it was saved with. An empty key
 * means the provider takes none — never "keep the saved one".
 */
export async function replaceAiConnection(baseUrl: string, apiKey: string): Promise<void> {
    await apiRequest('/v1/settings/ai/connection', {
        method: 'PUT',
        body: { base_url: baseUrl, api_key: apiKey === '' ? null : apiKey },
    });
}

/**
 * `POST /api/v1/settings/ai/models` — asks the saved provider which models it serves.
 *
 * Always the saved address and key; `provider` only chooses how the request is shaped, for a provider
 * picked on the form but not yet saved. Failure is a value, not a thrown error — "this provider cannot
 * list models" is an ordinary outcome, and the operator can always type a model name by hand.
 */
export interface AiModelList {
    ok: boolean;
    models: string[];
    reason: string | null;
}

export async function fetchAiModels(provider: string): Promise<AiModelList> {
    const result: unknown = JSON.parse(
        await apiRequest('/v1/settings/ai/models', { method: 'POST', body: { provider } }),
    );

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

/**
 * `GET /api/v1/settings/platform-data` — the official-source allowlist and how fresh each feed is.
 *
 * One request for both: "BNR is allowed" and "BNR last delivered nine days ago" are one answer split
 * in half, and the screen shows them as neighbouring cards.
 */
export async function getPlatformData(): Promise<PlatformData> {
    return parsePlatformData(JSON.parse(await apiRequest('/v1/settings/platform-data')));
}

/** `GET /api/v1/settings/health` — live state of the internal services this installation runs. */
export async function listSystemHealth(): Promise<ComponentHealth[]> {
    return parseSystemHealth(JSON.parse(await apiRequest('/v1/settings/health')));
}
