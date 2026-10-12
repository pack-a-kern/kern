import { apiRequest } from '../lib/http';
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



/** `DELETE /api/v1/admin/users/{id}/avatar` — takes somebody else's picture down. Removal only:
 *  choosing a face is the account holder's own, through `removeOwnAvatar`'s neighbour in `accountApi`. */
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
