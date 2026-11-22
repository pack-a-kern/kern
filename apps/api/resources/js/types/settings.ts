import { vArray, vBoolean, vEnum, vNullable, vNumber, vObject, vString } from '../lib/validate';

/**
 * System settings as `GET /api/v1/settings` returns them.
 *
 * A secret's `value` is always null — the API never returns one. `hasValue` says whether one is
 * set and `hint` carries its last four characters, which is enough to recognise a key you saved
 * and not enough to reconstruct it.
 */
export type SettingSource = 'database' | 'inherited';

/** Mirrors `Kern\Platform\Settings\Enums\SettingType`. */
export type SettingType =
    | 'string'
    | 'integer'
    | 'decimal'
    | 'boolean'
    | 'url'
    | 'secret'
    | 'currency_list'
    | 'country_list'
    | 'choice'
    | 'header_value'
    | 'contact';

export interface Setting {
    key: string;
    group: string;
    type: SettingType;
    /** The values a `choice` may hold, as stored; empty for every other type. The server refuses any other. */
    choices: string[];
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
        Array.isArray(candidate.choices) &&
        candidate.choices.every((choice) => typeof choice === 'string') &&
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

/**
 * One entry from `GET /api/v1/settings/ai/task-models` — an AI task and the model it will use.
 *
 * `model` is the name that will be sent, the task's own or the base `ai.model` it falls back to;
 * `usesDefault` tells which, so the screen can show an inherited model as inherited. `model` is null
 * when the task is off (an optional one nobody configured) or nothing is configured at all.
 */
export interface AiTaskModel {
    id: string;
    model: string | null;
    usesDefault: boolean;
    /** The settings key the screen edits this task's own model through; null for a task that follows the base model. */
    settingKey: string | null;
    /** True for a task whose unset state is "off" rather than the base model (the optional second reader). */
    optional: boolean;
}

export function parseAiTaskModels(payload: unknown): AiTaskModel[] {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Task-model response is not an object.');
    }

    const tasks = (payload as Record<string, unknown>).tasks;

    if (!Array.isArray(tasks)) {
        throw new TypeError('Task-model response has no tasks array.');
    }

    return tasks.map((entry): AiTaskModel => {
        if (typeof entry !== 'object' || entry === null) {
            throw new TypeError('Task-model entry is not an object.');
        }

        const task = entry as Record<string, unknown>;

        if (typeof task.id !== 'string') {
            throw new TypeError('Task-model entry is missing id.');
        }

        return {
            id: task.id,
            model: typeof task.model === 'string' ? task.model : null,
            usesDefault: task.uses_default === true,
            settingKey: typeof task.setting_key === 'string' ? task.setting_key : null,
            optional: task.optional === true,
        };
    });
}

/**
 * The result of `POST /api/v1/settings/ai/task-models/verify`.
 *
 * `checkable` false means the provider's model list could not be had (`reason` says why — the same
 * reasons the model loader uses), and the screen says "could not check" rather than marking models
 * missing. When true, `exists` maps each checked model name to whether the provider serves it.
 */
export interface AiModelVerification {
    checkable: boolean;
    reason: string | null;
    exists: Record<string, boolean>;
}

export function parseAiModelVerification(payload: unknown): AiModelVerification {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Model verification response is not an object.');
    }

    const candidate = payload as Record<string, unknown>;
    const exists: Record<string, boolean> = {};

    if (typeof candidate.exists === 'object' && candidate.exists !== null) {
        for (const [model, present] of Object.entries(candidate.exists as Record<string, unknown>)) {
            exists[model] = present === true;
        }
    }

    return {
        checkable: candidate.checkable === true,
        reason: typeof candidate.reason === 'string' ? candidate.reason : null,
        exists,
    };
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

/**
 * The logo folder as `GET /api/v1/settings/banks/logos` describes it: where logos are read from,
 * whether that folder exists, and the banks a person picks from that still have no logo — with the
 * file each would need inside it (`RO/CECEROBU.*`), or null for a bank no file can be matched to.
 */
export interface BankLogoCoverage {
    path: string;
    exists: boolean;
    banks: number;
    withLogo: number;
    missing: { id: string; name: string; file: string | null }[];
}

const bankLogoCoverage = vObject({
    path: vString,
    exists: vBoolean,
    banks: vNumber,
    with_logo: vNumber,
    missing: vArray(vObject({ id: vString, name: vString, file: vNullable(vString) })),
});

export function parseBankLogoCoverage(payload: unknown): BankLogoCoverage {
    const parsed = bankLogoCoverage(payload);

    return {
        path: parsed.path,
        exists: parsed.exists,
        banks: parsed.banks,
        withLogo: parsed.with_logo,
        missing: parsed.missing,
    };
}

/** The browsers the worker drives. Mirrors `Kern\Platform\BrowserWorker\BrowserEngine`. */
export const BROWSER_ENGINES = ['chromium', 'firefox', 'webkit'] as const;

export type BrowserEngine = (typeof BROWSER_ENGINES)[number];

/** A name the Browser card offers, as the list gave it. */
export interface UserAgentChoice {
    userAgent: string;
    browser: string;
    version: number;
}

/**
 * `GET /api/v1/settings/browser/user-agents` — the names, by engine and then by system, as the server
 * grouped and ordered them. The list's own date, or null before it was first read.
 */
export interface UserAgentChoices {
    publishedAt: string | null;
    engines: { engine: BrowserEngine; systems: { system: string; agents: UserAgentChoice[] }[] }[];
}

const userAgentChoices = vObject({
    publishedAt: vNullable(vString),
    engines: vArray(
        vObject({
            engine: vEnum(BROWSER_ENGINES),
            systems: vArray(
                vObject({
                    system: vString,
                    agents: vArray(vObject({ userAgent: vString, browser: vString, version: vNumber })),
                }),
            ),
        }),
    ),
});

export function parseUserAgentChoices(payload: unknown): UserAgentChoices {
    return userAgentChoices(payload);
}

/** `GET /api/v1/settings/browser/proxy` — never the password, only whether one is set. */
export interface BrowserProxyView {
    enabled: boolean;
    server: string | null;
    username: string | null;
    passwordSet: boolean;
    playbooks: string[];
    availablePlaybooks: string[];
    updatedAt: string | null;
}

const browserProxy = vObject({
    enabled: vBoolean,
    server: vNullable(vString),
    username: vNullable(vString),
    password_set: vBoolean,
    playbooks: vArray(vString),
    available_playbooks: vArray(vString),
    updated_at: vNullable(vString),
});

export function parseBrowserProxy(payload: unknown): BrowserProxyView {
    const parsed = browserProxy(payload);

    return {
        enabled: parsed.enabled,
        server: parsed.server,
        username: parsed.username,
        passwordSet: parsed.password_set,
        playbooks: parsed.playbooks,
        availablePlaybooks: parsed.available_playbooks,
        updatedAt: parsed.updated_at,
    };
}
