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
