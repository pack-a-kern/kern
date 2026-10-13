/**
 * System settings as `GET /api/v1/settings` returns them.
 *
 * A secret's `value` is always null — the API never returns one. `hasValue` says whether one is
 * set and `hint` carries its last four characters, which is enough to recognise a key you saved
 * and not enough to reconstruct it.
 */
export type SettingSource = 'database' | 'inherited';

/** Mirrors `Kern\Platform\Settings\Enums\SettingType`. */
export type SettingType = 'string' | 'integer' | 'decimal' | 'boolean' | 'url' | 'secret' | 'currency_list';

export interface Setting {
    key: string;
    group: string;
    type: SettingType;
    /** Where the effective value comes from. `database` means an override is stored here. */
    source: SettingSource;
    value: string | null;
    has_value: boolean;
    hint: string | null;
    /** Refused by the server, not merely disabled in the UI. */
    read_only: boolean;
    /** The configuration path this setting overrides — shown so an admin can find the fallback. */
    config_path: string;
    updated_at: string | null;
    updated_by: string | null;
}

export interface SettingGroupPayload {
    group: string;
    settings: Setting[];
}

function isSetting(value: unknown): value is Setting {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    return (
        typeof candidate.key === 'string' &&
        typeof candidate.group === 'string' &&
        typeof candidate.type === 'string' &&
        (candidate.source === 'database' || candidate.source === 'inherited') &&
        (candidate.value === null || typeof candidate.value === 'string') &&
        typeof candidate.has_value === 'boolean' &&
        (candidate.hint === null || typeof candidate.hint === 'string') &&
        typeof candidate.read_only === 'boolean' &&
        typeof candidate.config_path === 'string'
    );
}

/** Runtime validation at the API boundary (`BLUEPRINT.md` §19.5). */
export function parseSettingGroups(payload: unknown): SettingGroupPayload[] {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Settings response was not an object.');
    }

    const groups = (payload as Record<string, unknown>).groups;

    if (!Array.isArray(groups)) {
        throw new TypeError('Settings response carried no groups array.');
    }

    return groups.map((group) => {
        if (typeof group !== 'object' || group === null) {
            throw new TypeError('A settings group was not an object.');
        }

        const candidate = group as Record<string, unknown>;

        if (typeof candidate.group !== 'string' || !Array.isArray(candidate.settings)) {
            throw new TypeError('A settings group was malformed.');
        }

        const settings = candidate.settings.filter(isSetting);

        if (settings.length !== candidate.settings.length) {
            throw new TypeError(`Group "${candidate.group}" carried a malformed setting.`);
        }

        return { group: candidate.group, settings };
    });
}

/**
 * One entry from `GET /api/v1/settings/ai/providers` — a preset from `config/ai_providers.php`.
 *
 * Choosing one only prefills the form; it never becomes the saved configuration on its own, and
 * every field stays editable afterwards. `models` is a starting list that may be stale — the
 * "load from provider" button (available when `canListModels`) replaces it with the live one.
 */
export interface AiProvider {
    key: string;
    label: string;
    baseUrl: string | null;
    requiresApiKey: boolean;
    canListModels: boolean;
    models: string[];
    docsUrl: string | null;
}

function parseStringArray(value: unknown): string[] {
    return Array.isArray(value) ? value.filter((entry): entry is string => typeof entry === 'string') : [];
}

/**
 * Validates the provider catalogue at the boundary rather than trusting the response shape —
 * the same contract every other API module in `resources/js/api` follows.
 */
export function parseAiProviders(payload: unknown): AiProvider[] {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Provider catalogue response is not an object.');
    }

    const providers = (payload as Record<string, unknown>).providers;

    if (!Array.isArray(providers)) {
        throw new TypeError('Provider catalogue response has no providers array.');
    }

    return providers.map((entry): AiProvider => {
        if (typeof entry !== 'object' || entry === null) {
            throw new TypeError('Provider catalogue entry is not an object.');
        }

        const provider = entry as Record<string, unknown>;

        if (typeof provider.key !== 'string' || typeof provider.label !== 'string') {
            throw new TypeError('Provider catalogue entry is missing key or label.');
        }

        return {
            key: provider.key,
            label: provider.label,
            baseUrl: typeof provider.base_url === 'string' ? provider.base_url : null,
            requiresApiKey: provider.requires_api_key === true,
            canListModels: provider.can_list_models === true,
            models: parseStringArray(provider.models),
            docsUrl: typeof provider.docs_url === 'string' ? provider.docs_url : null,
        };
    });
}

/** One entry from `GET /api/v1/settings/health`. */
export interface ComponentHealth {
    key: string;
    state: 'healthy' | 'degraded' | 'not_configured';
    detail: string | null;
}

export function parseSystemHealth(payload: unknown): ComponentHealth[] {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Health response is not an object.');
    }

    const components = (payload as Record<string, unknown>).components;

    if (!Array.isArray(components)) {
        throw new TypeError('Health response has no components array.');
    }

    return components.map((entry): ComponentHealth => {
        const component = entry as Record<string, unknown>;
        const state = component.state;

        return {
            key: typeof component.key === 'string' ? component.key : 'unknown',
            state: state === 'healthy' || state === 'degraded' ? state : 'not_configured',
            detail: typeof component.detail === 'string' ? component.detail : null,
        };
    });
}
