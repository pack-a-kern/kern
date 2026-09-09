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
}

export interface TaxSubject {
    id: string;
    type: string;
    displayName: string;
    status: string;
    role: string;
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
    };
}

export function parseSubjects(payload: unknown): TaxSubject[] {
    const body = asRecord(payload, 'Subjects');
    // The endpoint paginates; `data` is the page. A cursor is not surfaced yet because a person
    // holding more tax subjects than one page is not a case this application has.
    const rows = Array.isArray(body.data) ? body.data : [];

    return rows.map((entry): TaxSubject => {
        const row = asRecord(entry, 'Subject');

        return {
            id: str(row, 'id'),
            type: str(row, 'type'),
            displayName: str(row, 'display_name'),
            status: str(row, 'status'),
            role: str(row, 'role'),
        };
    });
}
