import { apiRequest } from '../lib/http';
import {
    type AiModelVerification,
    type AiProvider,
    type AiTaskModel,
    type BankLogoCoverage,
    type BrowserEngine,
    type BrowserProxyView,
    type ComponentHealth,
    type SettingGroupPayload,
    type UserAgentChoices,
    parseAiModelVerification,
    parseAiProviders,
    parseAiTaskModels,
    parseBankLogoCoverage,
    parseBrowserProxy,
    parseSettingGroups,
    parseSystemHealth,
    parseUserAgentChoices,
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
 * `GET /api/v1/settings/ai/task-models` — every AI task and the model it will use.
 *
 * Config, not a provider call: it says which model each kind of work would send, resolving each
 * task's own model or the base one it inherits. `verifyAiModels` is the separate, on-demand call
 * that asks the provider whether those models exist.
 */
export async function getAiTaskModels(): Promise<AiTaskModel[]> {
    return parseAiTaskModels(JSON.parse(await apiRequest('/v1/settings/ai/task-models')));
}

/**
 * `POST /api/v1/settings/ai/task-models/verify` — asks the saved provider whether each task's model
 * exists on it.
 *
 * One provider call for all of them. `checkable` is false when the list could not be had (`reason`
 * says why), which the screen shows as "could not check" rather than as missing models.
 */
export async function verifyAiModels(): Promise<AiModelVerification> {
    return parseAiModelVerification(
        JSON.parse(await apiRequest('/v1/settings/ai/task-models/verify', { method: 'POST' })),
    );
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

/** `GET /api/v1/settings/banks/logos` — the logo folders, the one in use, and the banks still without one. */
export async function getBankLogos(): Promise<BankLogoCoverage> {
    return parseBankLogoCoverage(JSON.parse(await apiRequest('/v1/settings/banks/logos')));
}

/** `POST /api/v1/settings/banks/logos/refresh` — reads the folder again after files were added or replaced. */
export async function refreshBankLogos(): Promise<BankLogoCoverage> {
    return parseBankLogoCoverage(JSON.parse(await apiRequest('/v1/settings/banks/logos/refresh', { method: 'POST' })));
}

/** `GET /api/v1/settings/browser/user-agents` — the names the Browser card offers, by engine and system. */
export async function listUserAgents(): Promise<UserAgentChoices> {
    return parseUserAgentChoices(JSON.parse(await apiRequest('/v1/settings/browser/user-agents')));
}

/**
 * `PUT /api/v1/settings/browser/identity` — the engine and the User-Agent, saved together. Asks for the
 * password or code again first (`verify-recent`), which `apiRequest` answers through the step-up dialog.
 */
export async function replaceBrowserIdentity(engine: BrowserEngine, userAgent: string): Promise<void> {
    await apiRequest('/v1/settings/browser/identity', { method: 'PUT', body: { engine, user_agent: userAgent } });
}

/** `GET /api/v1/settings/browser/proxy` — the proxy, never its password. */
export async function getBrowserProxy(): Promise<BrowserProxyView> {
    return parseBrowserProxy(JSON.parse(await apiRequest('/v1/settings/browser/proxy')));
}

/**
 * `PUT /api/v1/settings/browser/proxy` — on with its address, user name and password, or off with none;
 * and the playbooks that use it. Behind `verify-recent`, like the identity.
 */
export async function replaceBrowserProxy(proxy: {
    enabled: boolean;
    server: string;
    username: string;
    password: string;
    playbooks: string[];
}): Promise<BrowserProxyView> {
    const body = proxy.enabled
        ? {
              enabled: true,
              server: proxy.server,
              username: proxy.username,
              password: proxy.password,
              playbooks: proxy.playbooks,
          }
        : { enabled: false, server: null, username: null, password: null, playbooks: proxy.playbooks };

    return parseBrowserProxy(JSON.parse(await apiRequest('/v1/settings/browser/proxy', { method: 'PUT', body })));
}
