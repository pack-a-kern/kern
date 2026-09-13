import { usePage } from '@inertiajs/react';

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
    user: { id: string; email: string | null; roles: string[] };
    subjects: { id: string; displayName: string; status: string; type: string }[];
    /** True while an administrator is acting as this account. */
    impersonated: boolean;
}

interface AuthPayload {
    user?: { id?: unknown; email?: unknown; roles?: unknown };
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
            roles: Array.isArray(payload.user?.roles) ? payload.user.roles.filter((role): role is string => typeof role === 'string') : [],
        },
        subjects: rows.map((entry) => {
            const row = (entry ?? {}) as Record<string, unknown>;

            return {
                id: asString(row.id),
                displayName: asString(row.display_name),
                status: asString(row.status),
                type: asString(row.type),
            };
        }),
    };
}
