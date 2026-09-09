import { type CursorPage, parseJson, vCursorPage, vEnum, vObject, vString } from '../lib/validate';

/** `App\Domain\Subjects\Enums\SubjectType`. Only `PF` has real application logic (`BLUEPRINT.md` §2.2). */
export const SUBJECT_TYPES = ['PF', 'PFA', 'II', 'IF', 'SRL'] as const;

export type SubjectType = (typeof SUBJECT_TYPES)[number];

/** `App\Domain\Subjects\Enums\SubjectStatus`. */
export const SUBJECT_STATUSES = ['ACTIVE', 'SUSPENDED', 'CLOSED'] as const;

export type SubjectStatus = (typeof SUBJECT_STATUSES)[number];

/** `App\Domain\Subjects\Enums\MembershipRole`. */
export const MEMBERSHIP_ROLES = ['OWNER', 'ADMIN', 'ACCOUNTANT', 'VIEWER'] as const;

export type MembershipRole = (typeof MEMBERSHIP_ROLES)[number];

/** One row of `GET /api/v1/subjects` — a `TaxSubject` the signed-in user can currently access. */
export interface SubjectSummary {
    id: string;
    type: SubjectType;
    displayName: string;
    status: SubjectStatus;
    role: MembershipRole;
}

const subjectSummaryValidator = vObject({
    id: vString,
    type: vEnum(SUBJECT_TYPES),
    display_name: vString,
    status: vEnum(SUBJECT_STATUSES),
    role: vEnum(MEMBERSHIP_ROLES),
});

function toSubjectSummary(parsed: ReturnType<typeof subjectSummaryValidator>): SubjectSummary {
    return {
        id: parsed.id,
        type: parsed.type,
        displayName: parsed.display_name,
        status: parsed.status,
        role: parsed.role,
    };
}

export function parseSubjectSummary(text: string): SubjectSummary {
    return toSubjectSummary(parseJson(text, subjectSummaryValidator));
}

/** `GET /api/v1/subjects` — the full cursor-paginated envelope (`BLUEPRINT.md` §18). */
export function parseSubjectsPage(text: string): CursorPage<SubjectSummary> {
    const page = parseJson(text, vCursorPage(subjectSummaryValidator));

    return { ...page, data: page.data.map(toSubjectSummary) };
}

/** `POST /api/v1/subjects/pf` — the request body `App\Http\Controllers\Api\V1\Subjects\RegisterPfSubjectController` validates. */
export interface RegisterPfSubjectInput {
    displayName: string;
    firstName: string;
    lastName: string;
    cnp: string;
    birthDate: string;
    countyCode?: string;
    locality?: string;
    address?: string;
    anafTaxOfficeCode?: string;
}

/** `POST /api/v1/subjects/pf` — `201` success body. */
export function parseRegisteredSubjectId(text: string): { id: string } {
    return parseJson(text, vObject({ id: vString }));
}
