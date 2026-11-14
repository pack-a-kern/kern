import { nullableStr, num, str } from '../lib/json';

/**
 * The platform role that may hand out roles, impersonate and erase — `SuperAdministratorRole::NAME` on
 * the server. Named once here, so the screen and the rules it mirrors cannot spell it two ways.
 */
export const SUPER_ADMIN_ROLE = 'SUPER_ADMIN';

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

/** Counts over every account, whatever the page shows. */
export interface DirectoryStats {
    users: number;
    withMfa: number;
    /** Their share of every account, as a decimal; null with no accounts. */
    mfaShare: string | null;
    /** Every account has a second factor. */
    allCovered: boolean;
    /** Platform role → how many accounts hold it. */
    roles: Record<string, number>;
}

export interface AdminDirectory {
    /** One page of the accounts the filters match. */
    users: AdminUser[];
    /** How many accounts the filters match, over every page. */
    total: number;
    page: number;
    stats: DirectoryStats;
    roles: RoleCount[];
    /**
     * What this installation actually enforces. `rolesEnforced` is false today: `MembershipRole`
     * is recorded on every membership but never consulted to permit or deny anything, so the
     * screen must say so rather than imply the roles are doing work.
     */
    enforcement: { rolesEnforced: boolean; settingsAdmins: number };
}

export function parseAdminDirectory(payload: unknown): AdminDirectory {
    if (typeof payload !== 'object' || payload === null) {
        throw new TypeError('Directory response is not an object.');
    }

    const body = payload as Record<string, unknown>;

    if (
        !Array.isArray(body.users) ||
        !Array.isArray(body.roles) ||
        typeof body.stats !== 'object' ||
        body.stats === null
    ) {
        throw new TypeError('Directory response is missing users, roles or stats.');
    }

    const stats = body.stats as Record<string, unknown>;
    const heldRoles = (typeof stats.roles === 'object' && stats.roles !== null ? stats.roles : {}) as Record<
        string,
        unknown
    >;

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
        total: num(body, 'total'),
        page: num(body, 'page'),
        stats: {
            users: num(stats, 'users'),
            withMfa: num(stats, 'with_mfa'),
            mfaShare: typeof stats.mfa_share === 'string' ? stats.mfa_share : null,
            allCovered: stats.all_covered === true,
            roles: Object.fromEntries(Object.keys(heldRoles).map((role) => [role, num(heldRoles, role)])),
        },
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
    /** How many permissions there are, across every group — counted by the server. */
    permissionCount: number;
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
        permissionCount: num(body, 'permission_count'),
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
