/**
 * Shapes for the "My account" screens.
 *
 * A `Profile` is deliberately thin: this application stores almost nothing *about* a person on
 * `users`. Names, CNP and addresses belong to a tax subject and are encrypted with that subject's
 * own key, which is why they appear on the Tax subjects screen and not here.
 */
export interface Profile {
    id: string;
    email: string;
    status: string | null;
    emailVerifiedAt: string | null;
    createdAt: string | null;
    hasPassword: boolean;
    roles: string[];
    /** When the picture last changed, or null for an account showing its initials. */
    avatarUpdatedAt: string | null;
}

export interface TaxSubject {
    id: string;
    type: string;
    displayName: string;
    status: string;
    role: string;
    /** Which currency this subject's wallet totals are added up in. A display preference only. */
    displayCurrency: string;
    /** When the picture last changed, or null for a taxpayer showing its initials. */
    avatarUpdatedAt: string | null;
}

function str(row: Record<string, unknown>, key: string): string {
    const value = row[key];

    return typeof value === 'string' ? value : '';
}

function nullableStr(row: Record<string, unknown>, key: string): string | null {
    const value = row[key];

    return typeof value === 'string' ? value : null;
}

function asRecord(payload: unknown, what: string): Record<string, unknown> {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError(`${what} response is not an object.`);
    }

    return payload as Record<string, unknown>;
}

export function parseProfile(payload: unknown): Profile {
    const row = asRecord(payload, 'Profile');

    return {
        id: str(row, 'id'),
        email: str(row, 'email'),
        status: nullableStr(row, 'status'),
        emailVerifiedAt: nullableStr(row, 'email_verified_at'),
        createdAt: nullableStr(row, 'created_at'),
        hasPassword: row.has_password === true,
        roles: Array.isArray(row.roles) ? row.roles.filter((r): r is string => typeof r === 'string') : [],
        avatarUpdatedAt: nullableStr(row, 'avatar_updated_at'),
    };
}

/** One page of taxpayers, in the shape the documents list already uses. */
export interface SubjectsPage {
    data: TaxSubject[];
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

export function parseSubjectsPage(payload: unknown): SubjectsPage {
    const body = asRecord(payload, 'Subjects');
    const rows = Array.isArray(body.data) ? body.data : [];

    // The pager's numbers, each falling back to what the page itself shows — an older response
    // that omits them renders one honest page rather than an empty one.
    const number = (key: string, fallback: number): number => {
        const value = body[key];

        return typeof value === 'number' ? value : fallback;
    };

    const data = rows.map((entry): TaxSubject => {
        const row = asRecord(entry, 'Subject');

        return {
            id: str(row, 'id'),
            type: str(row, 'type'),
            displayName: str(row, 'display_name'),
            status: str(row, 'status'),
            role: str(row, 'role'),
            // Empty when an older response omits it; the card falls back to RON rather than showing
            // a blank select.
            displayCurrency: str(row, 'display_currency'),
            avatarUpdatedAt: nullableStr(row, 'avatar_updated_at'),
        };
    });

    return {
        data,
        page: number('current_page', 1),
        perPage: number('per_page', data.length),
        total: number('total', data.length),
        lastPage: number('last_page', 1),
    };
}
