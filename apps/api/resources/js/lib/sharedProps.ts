import { router, usePage } from '@inertiajs/react';

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

/** The page sizes every list accepts, and the one a list opens at. */
export interface Paging {
    sizes: number[];
    default: number;
}

/** `paging` as the server shared it; refused when it is not there, rather than replaced by a guess. */
export function pagingFrom(props: unknown): Paging {
    const value = (props as { paging?: { sizes?: unknown; default?: unknown } } | null | undefined)?.paging;
    const sizes = Array.isArray(value?.sizes)
        ? value.sizes.filter((size): size is number => Number.isInteger(size) && size > 0)
        : [];
    const fallback = value?.default;

    if (sizes.length === 0 || typeof fallback !== 'number' || !sizes.includes(fallback)) {
        throw new TypeError('The page props carry no page sizes (`paging`, KERN_PAGE_SIZES).');
    }

    return { sizes, default: fallback };
}

/**
 * The page sizes, shared by `HandleInertiaRequests::share()` from `KERN_PAGE_SIZES`: the pager offers
 * exactly what the server accepts, with no list of its own.
 */
export function usePaging(): Paging {
    return pagingFrom(usePage().props);
}

/** The keys of the services not answering, for a reader of a `down` list sent by the server. */
export function downServicesFrom(value: unknown): string[] {
    return Array.isArray(value) ? value.filter((key): key is string => typeof key === 'string') : [];
}

/**
 * Which services were down at the scheduler's last look — or null for someone who may not read the
 * system status, who is never shown it (`HandleInertiaRequests`'s `systemHealth`).
 */
export function useSystemHealthDown(): string[] | null {
    const payload = usePage().props.systemHealth as { down?: unknown } | null | undefined;

    return payload === null || payload === undefined ? null : downServicesFrom(payload.down);
}

/**
 * What every screen is handed by the page it arrived on.
 *
 * `HandleInertiaRequests::share()` puts the signed-in account into the page response, so the shell
 * can draw itself without asking a second time. This is the one place that shape is read, so a
 * change on the server is a change in one file here.
 *
 * Absent on the guest screens, which have no account to describe — hence the null.
 */
export interface SharedAuth {
    /** `permissions` only decides what is drawn; every route checks its own. */
    user: { id: string; email: string | null; roles: string[]; permissions: string[] };
    /** True while an administrator is acting as this account. */
    impersonated: boolean;
}

interface AuthPayload {
    user?: { id?: unknown; email?: unknown; roles?: unknown; permissions?: unknown };
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
    };
}

/** One taxpayer the switcher offers. */
export interface SharedSubject {
    id: string;
    displayName: string;
    type: string;
    avatarUpdatedAt: string | null;
}

/**
 * The taxpayers this person can work in now — every one in use, closed ones never sent
 * (`HandleInertiaRequests`'s `subjects`). Empty on the guest screens.
 */
export function useSharedSubjects(): SharedSubject[] {
    const rows: unknown = usePage().props.subjects;

    return (Array.isArray(rows) ? rows : []).map((entry: unknown): SharedSubject => {
        const row = (entry ?? {}) as Record<string, unknown>;

        return {
            id: asString(row.id),
            displayName: asString(row.display_name),
            type: asString(row.type),
            avatarUpdatedAt: typeof row.avatar_updated_at === 'string' ? row.avatar_updated_at : null,
        };
    });
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

/**
 * Asks for the signed-in person's shared props again — who they are and which taxpayers they work in
 * — and nothing else of the page. `subjects` is a once prop, and a reload that names it reads it anew. The frame stays mounted between screens and draws its switcher from
 * these, so a change that is not a navigation (a picture uploaded, a taxpayer shared from another
 * device) reaches it this way: one small request, the page and its state left as they are.
 */
export function refreshSharedAuth(): void {
    router.reload({ only: ['auth', 'subjects'] });
}
