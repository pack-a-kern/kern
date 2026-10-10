/** Shapes returned by `GET /api/v1/admin/users`. */
export interface AdminUser {
    id: string;
    email: string;
    status: string;
    createdAt: string | null;
    passkeys: number;
    totp: boolean;
    lastSeenAt: string | null;
    /** Per-tax-subject membership roles (OWNER/ADMIN/…). */
    memberships: string[];
    /** Platform roles from `config/access.php` — what this user may do to the installation. */
    roles: string[];
    /**
     * When this account's picture was last changed, or null for an account showing its initials.
     *
     * The timestamp rather than a URL, and it is both the flag and the cache key: the stored file keeps
     * one name per user, so this is what makes each new upload a different URL for the browser.
     */
    avatarUpdatedAt: string | null;
}

export interface RoleCount {
    role: string;
    members: number;
}

export interface AdminDirectory {
    users: AdminUser[];
    truncated: boolean;
    roles: RoleCount[];
    /**
     * What this installation actually enforces. `rolesEnforced` is false today: `MembershipRole`
     * is recorded on every membership but never consulted to permit or deny anything, so the
     * screen must say so rather than imply the roles are doing work.
     */
    enforcement: { rolesEnforced: boolean; settingsAdmins: number };
}

function str(row: Record<string, unknown>, key: string): string {
    const value = row[key];

    return typeof value === 'string' ? value : '';
}

function nullableStr(row: Record<string, unknown>, key: string): string | null {
    const value = row[key];

    return typeof value === 'string' ? value : null;
}

function num(row: Record<string, unknown>, key: string): number {
    const value = row[key];

    return typeof value === 'number' ? value : 0;
}

export function parseAdminDirectory(payload: unknown): AdminDirectory {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Directory response is not an object.');
    }

    const body = payload as Record<string, unknown>;

    if (!Array.isArray(body.users) || !Array.isArray(body.roles)) {
        throw new TypeError('Directory response is missing users or roles.');
    }

    const enforcement = (
        typeof body.enforcement === 'object' && body.enforcement !== null ? body.enforcement : {}
    ) as Record<string, unknown>;

    return {
        users: body.users.map((entry): AdminUser => {
            if (typeof entry !== 'object' || entry === null) {
                throw new TypeError('Directory user is not an object.');
            }

            const row = entry as Record<string, unknown>;

            return {
                id: str(row, 'id'),
                email: str(row, 'email'),
                status: str(row, 'status'),
                createdAt: nullableStr(row, 'created_at'),
                passkeys: num(row, 'passkeys'),
                totp: row.totp === true,
                lastSeenAt: nullableStr(row, 'last_seen_at'),
                avatarUpdatedAt: nullableStr(row, 'avatar_updated_at'),
                memberships: Array.isArray(row.memberships)
                    ? row.memberships.filter((value): value is string => typeof value === 'string')
                    : [],
                roles: Array.isArray(row.roles)
                    ? row.roles.filter((value): value is string => typeof value === 'string')
                    : [],
            };
        }),
        truncated: body.truncated === true,
        roles: body.roles.map((entry): RoleCount => {
            const row = (typeof entry === 'object' && entry !== null ? entry : {}) as Record<string, unknown>;

            return { role: str(row, 'role'), members: num(row, 'members') };
        }),
        enforcement: {
            rolesEnforced: enforcement.roles_enforced === true,
            settingsAdmins: num(enforcement, 'settings_admins'),
        },
    };
}

/** One row of the permission matrix, from `GET /api/v1/admin/access`. */
export interface PermissionRow {
    permission: string;
    /** False when no route or policy checks this permission yet. */
    enforced: boolean;
    roles: Record<string, boolean>;
}

export interface AccessCatalogue {
    roles: string[];
    groups: Array<{ group: string; permissions: PermissionRow[] }>;
}

export function parseAccessCatalogue(payload: unknown): AccessCatalogue {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Access catalogue response is not an object.');
    }

    const body = payload as Record<string, unknown>;

    if (!Array.isArray(body.roles) || !Array.isArray(body.groups)) {
        throw new TypeError('Access catalogue response is missing roles or groups.');
    }

    return {
        roles: body.roles.filter((role): role is string => typeof role === 'string'),
        groups: body.groups.map((entry) => {
            const group = (typeof entry === 'object' && entry !== null ? entry : {}) as Record<string, unknown>;
            const permissions = Array.isArray(group.permissions) ? group.permissions : [];

            return {
                group: str(group, 'group'),
                permissions: permissions.map((row): PermissionRow => {
                    const item = (typeof row === 'object' && row !== null ? row : {}) as Record<string, unknown>;
                    const roles = (typeof item.roles === 'object' && item.roles !== null ? item.roles : {}) as Record<
                        string,
                        unknown
                    >;

                    return {
                        permission: str(item, 'permission'),
                        enforced: item.enforced === true,
                        roles: Object.fromEntries(Object.entries(roles).map(([role, held]) => [role, held === true])),
                    };
                }),
            };
        }),
    };
}
