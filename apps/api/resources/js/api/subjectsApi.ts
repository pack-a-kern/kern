import { ApiError, apiRequest } from '../lib/http';
import type { CursorPage } from '../lib/validate';
import {
    type RegisterPfSubjectInput,
    type SubjectSummary,
    parseRegisteredSubjectId,
    parseSubjectsPage,
} from '../types/subjects';

/**
 * Thrown when `POST /api/v1/subjects/pf` rejects a CNP already registered under a different
 * `TaxSubject` (`422 {"reason": "DUPLICATE_CNP"}` —
 * `App\Domain\Subjects\Exceptions\DuplicateCnpException`). Distinguished from a generic
 * {@link ApiError} the same way `documentsApi.ts`'s `DocumentRejectedError` is, so the
 * registration form can show a specific message rather than a raw validation error.
 */
export class DuplicateCnpError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'DuplicateCnpError';
    }
}

/**
 * `GET /api/v1/subjects` — every `TaxSubject` the signed-in user holds a non-revoked membership
 * on, cursor-paginated (`BLUEPRINT.md` §18). Replaces `resources/js/lib/subjectStore.ts`'s old
 * "type your subject id" stopgap (`docs/handoff/030-pwa-frontend.md` Key decision #1,
 * `docs/handoff/031-pwa-backend-endpoints.md`).
 */
export async function listSubjects(cursor?: string): Promise<CursorPage<SubjectSummary>> {
    const query = cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
    const text = await apiRequest(`/v1/subjects${query}`);

    return parseSubjectsPage(text);
}

/**
 * `POST /api/v1/subjects/pf` — registers a new PF `TaxSubject`; the caller becomes its `OWNER`.
 * First-run flow for a user with no subjects yet, and available at any time afterwards to add
 * another PF.
 */
export async function registerPfSubject(input: RegisterPfSubjectInput): Promise<{ id: string }> {
    try {
        const text = await apiRequest('/v1/subjects/pf', {
            method: 'POST',
            body: {
                display_name: input.displayName,
                first_name: input.firstName,
                last_name: input.lastName,
                cnp: input.cnp,
                birth_date: input.birthDate,
                county_code: input.countyCode ?? null,
                locality: input.locality ?? null,
                address: input.address ?? null,
                anaf_tax_office_code: input.anafTaxOfficeCode ?? null,
            },
        });

        return parseRegisteredSubjectId(text);
    } catch (error) {
        if (error instanceof ApiError && error.status === 422 && error.reason === 'DUPLICATE_CNP') {
            throw new DuplicateCnpError(error.message);
        }

        throw error;
    }
}
