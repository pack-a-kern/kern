import { usePage } from '@inertiajs/react';

/**
 * The product's name, as `APP_NAME` spells it.
 *
 * Shared by `HandleInertiaRequests::share()` rather than kept in the dictionaries: a name is not
 * translated, and a copy per language is a copy that goes stale. Read it here, never write it down.
 */
export function appNameFrom(props: unknown): string {
    const value = (props as { appName?: unknown } | null | undefined)?.appName;

    return typeof value === 'string' ? value : '';
}

export function useAppName(): string {
    return appNameFrom(usePage().props);
}

/**
 * The currencies this installation works in, base currency first.
 *
 * Shared by `HandleInertiaRequests::share()` from the `fx.currencies` setting. It used to be two
 * arrays written into the frontend — one here in `types/wallet.ts`, one in the dashboard — which
 * disagreed with each other and with an API that accepts any ISO 4217 code, and neither could be
 * changed without a deploy. Read it here; never write a list of codes down again.
 *
 * The fallback is the base currency alone rather than an empty list: a select with no options is a
 * form nobody can submit, and a Romanian tax product always has lei.
 */
export function useCurrencies(): string[] {
    const value = usePage().props.currencies;

    return Array.isArray(value) ? value.filter((code): code is string => typeof code === 'string') : ['RON'];
}

/**
 * What every screen is handed by the page it arrived on.
 *
 * `HandleInertiaRequests::share()` puts the signed-in account and its taxpayers into the page
 * response, so the shell can draw itself without asking a second time. This is the one place that
 * shape is read, so a change on the server is a change in one file here.
 *
 * Absent on the guest screens, which have no account to describe — hence the null.
 */
export interface SharedAuth {
    /** `permissions` only decides what is drawn; every route checks its own. */
    user: { id: string; email: string | null; roles: string[]; permissions: string[] };
    subjects: { id: string; displayName: string; status: string; type: string; avatarUpdatedAt: string | null }[];
    /** True while an administrator is acting as this account. */
    impersonated: boolean;
}

interface AuthPayload {
    user?: { id?: unknown; email?: unknown; roles?: unknown; permissions?: unknown };
    subjects?: unknown;
    impersonated?: unknown;
}

function asString(value: unknown, fallback = ''): string {
    return typeof value === 'string' ? value : fallback;
}

export function useSharedAuth(): SharedAuth | null {
    const payload = usePage().props.auth as AuthPayload | null | undefined;

    if (payload === null || payload === undefined) {
        return null;
    }

    const rows = Array.isArray(payload.subjects) ? payload.subjects : [];

    return {
        impersonated: payload.impersonated === true,
        user: {
            id: asString(payload.user?.id),
            email: typeof payload.user?.email === 'string' ? payload.user.email : null,
            roles: Array.isArray(payload.user?.roles)
                ? payload.user.roles.filter((role): role is string => typeof role === 'string')
                : [],
            permissions: Array.isArray(payload.user?.permissions)
                ? payload.user.permissions.filter((permission): permission is string => typeof permission === 'string')
                : [],
        },
        subjects: rows.map((entry) => {
            const row = (entry ?? {}) as Record<string, unknown>;

            return {
                id: asString(row.id),
                displayName: asString(row.display_name),
                status: asString(row.status),
                type: asString(row.type),
                avatarUpdatedAt: typeof row.avatar_updated_at === 'string' ? row.avatar_updated_at : null,
            };
        }),
    };
}

/**
 * What the upload picker may offer, decided by the server.
 *
 * `SniffedFileType::acceptAttribute()` is the one list of types the pipeline accepts; this carries
 * it to the `accept` attribute instead of the dialog keeping its own copy, which drifted the moment
 * a type was added on the server.
 *
 * The fallback is the two types that have always been accepted: a missing prop should narrow the
 * picker, never open it to files the server will refuse.
 */
export function useAcceptedUploadTypes(): string {
    const payload = usePage().props.documents as { accept?: unknown } | null | undefined;
    const accept = payload?.accept;

    return typeof accept === 'string' && accept !== '' ? accept : 'application/pdf,image/jpeg,image/png';
}

/**
 * Whether the configured provider can clean up a photographed document.
 *
 * A capability of the endpoint, not a preference — the `docscanner` extension is something a
 * gateway either implements or does not. False by default so the upload screen never offers
 * something that would fail.
 */
export function useProviderEnhances(): boolean {
    const payload = usePage().props.documents as { enhances?: unknown } | null | undefined;

    return payload?.enhances === true;
}
