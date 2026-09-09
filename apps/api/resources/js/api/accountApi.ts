import { apiRequest } from '../lib/http';
import { type Profile, type TaxSubject, parseProfile, parseSubjects } from '../types/account';

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
export async function listSubjects(): Promise<TaxSubject[]> {
    return parseSubjects(JSON.parse(await apiRequest('/v1/subjects')));
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
