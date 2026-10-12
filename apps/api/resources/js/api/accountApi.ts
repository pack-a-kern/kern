import { apiRequest, apiUpload } from '../lib/http';
import { type Profile, type SubjectsPage, parseProfile, parseSubjectsPage } from '../types/account';

/** `GET /api/v1/me` — the signed-in account. */
export async function getProfile(): Promise<Profile> {
    return parseProfile(JSON.parse(await apiRequest('/v1/me')));
}

/** `PUT /api/v1/me/password` — the current password is required even on an authenticated session. */
export async function changePassword(input: {
    currentPassword: string;
    password: string;
    passwordConfirmation: string;
}): Promise<void> {
    await apiRequest('/v1/me/password', {
        method: 'PUT',
        body: {
            current_password: input.currentPassword,
            password: input.password,
            password_confirmation: input.passwordConfirmation,
        },
    });
}

/** `GET /api/v1/subjects` — the tax subjects this account is a member of. */
export async function listSubjects(page = 1, perPage?: number): Promise<SubjectsPage> {
    const query = new URLSearchParams();

    if (page > 1) {
        query.set('page', String(page));
    }

    if (perPage !== undefined) {
        query.set('per_page', String(perPage));
    }

    const suffix = query.size === 0 ? '' : `?${query.toString()}`;

    return parseSubjectsPage(JSON.parse(await apiRequest(`/v1/subjects${suffix}`)));
}

/** `POST/DELETE /api/v1/subjects/{id}/document-ai-consent` — per-subject consent to external AI. */
export async function setDocumentAiConsent(subjectId: string, consented: boolean): Promise<void> {
    await apiRequest(`/v1/subjects/${subjectId}/document-ai-consent`, {
        method: consented ? 'POST' : 'DELETE',
    });
}

/** `POST /api/v1/subjects/pf` — registers an individual taxpayer. */
export interface RegisterPfInput {
    displayName: string;
    firstName: string;
    lastName: string;
    cnp: string;
    birthDate: string;
    countyCode: string;
    locality: string;
    address: string;
}

export async function registerPfSubject(input: RegisterPfInput): Promise<void> {
    await apiRequest('/v1/subjects/pf', {
        method: 'POST',
        body: {
            display_name: input.displayName,
            first_name: input.firstName,
            last_name: input.lastName,
            cnp: input.cnp,
            birth_date: input.birthDate,
            // Optional server-side; omitted rather than sent empty, so a blank field does not
            // become an empty string in an encrypted column.
            ...(input.countyCode === '' ? {} : { county_code: input.countyCode }),
            ...(input.locality === '' ? {} : { locality: input.locality }),
            ...(input.address === '' ? {} : { address: input.address }),
        },
    });
}

/**
 * `DELETE /api/v1/auth/mfa/totp` — removes the authenticator app.
 *
 * The current password is required again even on an authenticated session: stripping a second
 * factor is exactly what someone holding a stolen session wants to do. An account with no password
 * signs in by passkey, which survives this, so none is asked for.
 */
export async function disableTotp(currentPassword: string | null): Promise<void> {
    await apiRequest('/v1/auth/mfa/totp', {
        method: 'DELETE',
        body: currentPassword === null ? {} : { current_password: currentPassword },
    });
}

/**
 * `GET /api/v1/documents/ai-consents` — the subjects that currently permit external AI processing.
 *
 * A set, not a value per subject: the consent screen shows a switch per subject and would
 * otherwise need one request per row to learn a single boolean each.
 */
export async function listDocumentAiConsents(): Promise<Set<string>> {
    const body: unknown = JSON.parse(await apiRequest('/v1/documents/ai-consents'));
    const ids = (body as Record<string, unknown>).consented_subject_ids;

    return new Set(Array.isArray(ids) ? ids.filter((id): id is string => typeof id === 'string') : []);
}

/** `PATCH /api/v1/subjects/{id}` — corrects a taxpayer's details. Owner only; the CNP is not
 *  correctable, because it is what identifies the taxpayer rather than a fact about them. */
export async function updateSubject(
    subjectId: string,
    input: Partial<Omit<RegisterPfInput, 'cnp' | 'birthDate'>>,
): Promise<void> {
    const body: Record<string, unknown> = {};

    if (input.displayName !== undefined) body.display_name = input.displayName;
    if (input.firstName !== undefined) body.first_name = input.firstName;
    if (input.lastName !== undefined) body.last_name = input.lastName;
    if (input.countyCode !== undefined) body.county_code = input.countyCode;
    if (input.locality !== undefined) body.locality = input.locality;
    if (input.address !== undefined) body.address = input.address;

    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}`, { method: 'PATCH', body });
}

/** `PUT /api/v1/subjects/{id}/status` — suspend, close, or reactivate. There is no delete: an
 *  archived taxpayer keeps its audit chain and every figure filed under it. */
export async function setSubjectStatus(subjectId: string, status: 'ACTIVE' | 'SUSPENDED' | 'CLOSED'): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/status`, {
        method: 'PUT',
        body: { status },
    });
}

/**
 * `POST /api/v1/subjects/cnp` — what a CNP contains, before anything is registered.
 *
 * The birth date and the county live inside the number, so the form asks for the CNP first and
 * fills the rest from this answer. Read-only on the server: nothing is stored, nothing is looked
 * up, and registration re-derives from the CNP it is given rather than trusting what came back
 * here.
 */
export interface CnpDetails {
    sex: 'MALE' | 'FEMALE';
    foreignResident: boolean;
    birthDate: string;
    countyCode: string;
    /** Only Bucharest's sectors are named by a CNP; everywhere else this is null. */
    locality: string | null;
}

export async function inspectCnp(cnp: string): Promise<CnpDetails> {
    const payload: unknown = JSON.parse(await apiRequest('/v1/subjects/cnp', { method: 'POST', body: { cnp } }));
    const row = (payload ?? {}) as Record<string, unknown>;

    return {
        sex: row.sex === 'FEMALE' ? 'FEMALE' : 'MALE',
        foreignResident: row.foreign_resident === true,
        birthDate: typeof row.birth_date === 'string' ? row.birth_date : '',
        countyCode: typeof row.county_code === 'string' ? row.county_code : '',
        locality: typeof row.locality === 'string' ? row.locality : null,
    };
}

/**
 * `GET /api/v1/subjects/{id}/avatar` — the URL a taxpayer's picture is read from.
 *
 * Not a request: this builds the `src` an `<img>` fetches itself, which is why the timestamp is in
 * the query string. The file keeps one name per taxpayer and is overwritten on each upload, so
 * without the version a browser would hold the previous picture for as long as the cache header says
 * — a year. Same shape as `avatarUrl` in `adminApi`, pointing at the other owner.
 */
export function subjectAvatarUrl(subjectId: string, version: string): string {
    return `/api/v1/subjects/${encodeURIComponent(subjectId)}/avatar?v=${encodeURIComponent(version)}`;
}

/** `POST /api/v1/subjects/{id}/avatar` — gives a taxpayer a picture. Owner only. */
export async function uploadSubjectAvatar(subjectId: string, file: File): Promise<string | null> {
    const body = new FormData();
    body.append('avatar', file);

    const response: unknown = JSON.parse(
        await apiUpload(`/v1/subjects/${encodeURIComponent(subjectId)}/avatar`, body),
    );

    if (typeof response !== 'object' || response === null) {
        return null;
    }

    const updated = (response as Record<string, unknown>).avatar_updated_at;

    return typeof updated === 'string' ? updated : null;
}

/** `DELETE /api/v1/subjects/{id}/avatar` — puts the initials back. */
export async function removeSubjectAvatar(subjectId: string): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/avatar`, { method: 'DELETE' });
}

/**
 * `GET /api/v1/users/{id}/avatar` — the URL a person's picture is read from.
 *
 * Not a request: this builds the `src` an `<img>` fetches itself, which is why the timestamp is in
 * the query string. The file keeps one name per owner and is overwritten on each upload, so without
 * the version a browser would hold the previous face for as long as the cache header says — a year.
 *
 * Here rather than in `adminApi`, where it used to live: the route is open to any signed-in user for
 * their own picture, so it was never an administrative call.
 */
export function userAvatarUrl(userId: string, version: string): string {
    return `/api/v1/users/${encodeURIComponent(userId)}/avatar?v=${encodeURIComponent(version)}`;
}

/** `POST /api/v1/me/avatar` — gives your own account a picture. No id, so no permission. */
export async function uploadOwnAvatar(file: File): Promise<string | null> {
    const body = new FormData();
    body.append('avatar', file);

    const response: unknown = JSON.parse(await apiUpload('/v1/me/avatar', body));

    if (typeof response !== 'object' || response === null) {
        return null;
    }

    const updated = (response as Record<string, unknown>).avatar_updated_at;

    return typeof updated === 'string' ? updated : null;
}

/** `DELETE /api/v1/me/avatar` — puts your initials back. */
export async function removeOwnAvatar(): Promise<void> {
    await apiRequest('/v1/me/avatar', { method: 'DELETE' });
}
