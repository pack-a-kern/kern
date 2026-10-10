import { apiRequest, apiUpload } from '../lib/http';
import { type AccessCatalogue, type AdminDirectory, parseAccessCatalogue, parseAdminDirectory } from '../types/admin';

/** `GET /api/v1/admin/users` — accounts, their MFA posture, memberships, and what is enforced. */
export async function getAdminDirectory(): Promise<AdminDirectory> {
    return parseAdminDirectory(JSON.parse(await apiRequest('/v1/admin/users')));
}

/** `GET /api/v1/admin/access` — the permission matrix as configured in the database. */
export async function getAccessCatalogue(): Promise<AccessCatalogue> {
    return parseAccessCatalogue(JSON.parse(await apiRequest('/v1/admin/access')));
}

/** `PUT /api/v1/admin/users/{id}/roles` — replaces a user's platform roles. */
export async function updateUserRoles(userId: string, roles: string[]): Promise<void> {
    await apiRequest(`/v1/admin/users/${userId}/roles`, { method: 'PUT', body: { roles } });
}

/** `PUT /api/v1/admin/users/{id}/status` — suspends or reactivates an account. */
export async function updateUserStatus(userId: string, status: string): Promise<void> {
    await apiRequest(`/v1/admin/users/${userId}/status`, { method: 'PUT', body: { status } });
}

/**
 * `GET /api/v1/users/{id}/avatar` — the URL a picture is read from.
 *
 * Not a request: this builds the `src` an `<img>` fetches itself, which is why the timestamp is in the
 * query string. The file keeps one name per user and is overwritten on each upload, so without the
 * version a browser would hold the previous face for as long as the cache header says — a year.
 */
export function avatarUrl(userId: string, version: string): string {
    return `/api/v1/users/${encodeURIComponent(userId)}/avatar?v=${encodeURIComponent(version)}`;
}

/** `POST /api/v1/admin/users/{id}/avatar` — gives an account a picture. */
export async function uploadUserAvatar(userId: string, file: File): Promise<string | null> {
    const body = new FormData();
    body.append('avatar', file);

    const response: unknown = JSON.parse(
        await apiUpload(`/v1/admin/users/${encodeURIComponent(userId)}/avatar`, body),
    );

    if (typeof response !== 'object' || response === null) {
        return null;
    }

    const updated = (response as Record<string, unknown>).avatar_updated_at;

    return typeof updated === 'string' ? updated : null;
}

/** `DELETE /api/v1/admin/users/{id}/avatar` — puts the initials back. */
export async function removeUserAvatar(userId: string): Promise<void> {
    await apiRequest(`/v1/admin/users/${encodeURIComponent(userId)}/avatar`, { method: 'DELETE' });
}

/**
 * `DELETE /api/v1/admin/users/{id}` — erases a person: credentials, sessions, memberships and the
 * email itself. Their audit trail stays, because a chain that can be edited is not a chain.
 */
export async function eraseUser(userId: string): Promise<void> {
    await apiRequest(`/v1/admin/users/${encodeURIComponent(userId)}`, { method: 'DELETE' });
}

/** `POST /api/v1/admin/users/{id}/impersonate` — continue as that person until you stop. */
export async function impersonateUser(userId: string): Promise<void> {
    await apiRequest(`/v1/admin/users/${encodeURIComponent(userId)}/impersonate`, { method: 'POST' });
}

/** `POST /api/v1/admin/impersonation/stop` — back to your own account. */
export async function stopImpersonating(): Promise<void> {
    await apiRequest('/v1/admin/impersonation/stop', { method: 'POST' });
}

/**
 * `POST /api/v1/admin/users` — opens an account for somebody else.
 *
 * The generated password comes back in this one response and cannot be retrieved afterwards: only
 * its hash is stored. Whatever calls this owns showing it to the administrator once.
 */
export async function createUser(email: string, roles: string[]): Promise<{ id: string; password: string }> {
    const payload: unknown = JSON.parse(
        await apiRequest('/v1/admin/users', { method: 'POST', body: { email, roles } }),
    );
    const row = (payload ?? {}) as Record<string, unknown>;

    return {
        id: typeof row.id === 'string' ? row.id : '',
        password: typeof row.password === 'string' ? row.password : '',
    };
}
