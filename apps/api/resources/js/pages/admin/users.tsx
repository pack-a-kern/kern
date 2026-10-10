import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import {
    Check as CheckIcon,
    Calculator,
    CircleDot,
    Eye,
    EllipsisVertical,
    Fingerprint,
    FilterX,
    KeyRound,
    LogIn,
    Pencil,
    Plus,
    RotateCcw,
    Shield,
    ShieldCheck,
    Trash2,
    UserSearch,
    UsersIcon,
    X,
} from 'lucide-react';
import { toast } from 'sonner';

import {
    eraseUser,
    getAccessCatalogue,
    getAdminDirectory,
    impersonateUser,
    updateUserRoles,
    updateUserStatus,
} from '../../api/adminApi';
import AppShell from '../../components/AppShell';
import {
    Check,
    DataTable,
    EmptyState,
    Field,
    FieldGrid,
    Identity,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelSelect,
    Pill,
    SearchInput,
    Stat,
    StatGrid,
    Status,
    Tabs,
    Toolbar,
} from '../../components/panel';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '../../components/ui/dropdown-menu';
import Pagination from '../../components/Pagination';
import { useRememberedPaging, useRememberedState } from '../../hooks/useRememberedState';
import ConfirmDialog from '../../components/ConfirmDialog';
import NewUserDialog from './NewUserDialog';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { useSharedAuth } from '../../lib/sharedProps';
import { ApiError } from '../../lib/http';
import type { AccessCatalogue, AdminDirectory, AdminUser } from '../../types/admin';

type Tab = 'users' | 'roles' | 'permissions';

const TABS: readonly Tab[] = ['users', 'roles', 'permissions'];

/** Initials for the avatar disc. We hold no display name, so the email's local part is the name. */
function initialsFor(email: string): string {
    const local = email.split('@')[0] ?? email;
    const parts = local.split(/[._-]+/).filter((part) => part !== '');

    return (parts.length > 1 ? `${parts[0]?.[0] ?? ''}${parts[1]?.[0] ?? ''}` : local.slice(0, 2)).toUpperCase();
}

/**
 * Users, roles and permissions.
 *
 * Every cell of the permission matrix is read from `role_has_permissions`, so the screen shows what
 * is in force rather than what was intended. A permission that is granted but that no route checks
 * yet renders outlined instead of filled — see `Check` — because a matrix that ticks an unenforced
 * permission is worse than one that omits it.
 */
/**
 * A mark for each role, so the four cards are told apart before their names are read.
 *
 * A role this screen has not been taught about gets the generic shield rather than nothing: an unmarked card
 * beside four marked ones reads as broken, where a neutral mark reads as "new".
 */
function roleIcon(role: string): JSX.Element {
    switch (role) {
        case 'SUPER_ADMIN':
            return <ShieldCheck className="size-4" />;
        case 'ACCOUNTANT':
            return <Calculator className="size-4" />;
        case 'VIEWER':
            return <Eye className="size-4" />;
        default:
            return <Shield className="size-4" />;
    }
}

export default function AdminUsers(): JSX.Element {
    const { t } = useTranslation();

    const [tab, setTab] = useRememberedState<Tab>('admin.users.tab', 'users', {
        isValid: (value) => TABS.includes(value),
    });
    const [directory, setDirectory] = useState<AdminDirectory | null>(null);
    const [access, setAccess] = useState<AccessCatalogue | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [failed, setFailed] = useState(false);
    const [search, setSearch] = useRememberedState<string>('admin.users.search', '');
    const [roleFilter, setRoleFilter] = useRememberedState<string>('admin.users.role', '');
    const [statusFilter, setStatusFilter] = useRememberedState<string>('admin.users.status', '', {
        isValid: (value) => ['', 'ACTIVE', 'SUSPENDED', 'DELETED'].includes(value),
    });
    const [editing, setEditing] = useState<AdminUser | null>(null);
    const [reloadToken, setReloadToken] = useState(0);

    const describeFailure = useCallback((cause: unknown): string => describeApiFailure(cause, t), [t]);

    const reload = useCallback(() => {
        setDirectory(null);
        setAccess(null);
        setFailed(false);
        setReloadToken((token) => token + 1);
    }, []);

    useEffect(() => {
        let active = true;

        getAdminDirectory()
            .then((loaded) => {
                if (active) {
                    setDirectory(loaded);
                    setFailed(false);
                }
            })
            .catch((cause: unknown) => {
                if (!active) {
                    return;
                }

                if (cause instanceof ApiError && cause.status === 403) {
                    setForbidden(true);

                    return;
                }

                setFailed(true);
                toast.error(
                    t((d) => d.pages.adminUsers.loadFailed),
                    { description: describeFailure(cause) },
                );
            });

        void getAccessCatalogue().then(
            (loaded) => {
                if (active) {
                    setAccess(loaded);
                }
            },
            () => {
                if (active) {
                    setAccess(null);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [reloadToken, describeFailure, t]);

    /*
     * Who is looking, and at whom.
     *
     * Acting *as* someone, and erasing them, are a super administrator's to take — and never on
     * themselves: an administrator who impersonates their own account learns nothing, and one who
     * erases it locks everybody out of the installation. Both come from the page's own shared
     * props, so the check does not need a request of its own.
     */
    const auth = useSharedAuth();
    const currentUserId = auth?.user.id ?? '';
    const isSuperAdmin = auth?.user.roles.includes('SUPER_ADMIN') === true;

    const [impersonating, setImpersonating] = useState<AdminUser | null>(null);
    const [erasing, setErasing] = useState<AdminUser | null>(null);
    const [acting, setActing] = useState(false);
    const [creatingUser, setCreatingUser] = useState(false);

    const startImpersonation = async (user: AdminUser): Promise<void> => {
        setActing(true);

        try {
            await impersonateUser(user.id);
            // A full load, not a client-side visit: every screen behind this one was rendered for
            // somebody else, and the session that fetched it is no longer the session in force.
            window.location.assign('/dashboard');
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminUsers.loginAsFailed),
                { description: describeFailure(cause) },
            );
            setActing(false);
            setImpersonating(null);
        }
    };

    const erase = async (user: AdminUser): Promise<void> => {
        setActing(true);

        try {
            await eraseUser(user.id);
            toast.success(t((d) => d.pages.adminUsers.erased));
            setErasing(null);
            reload();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminUsers.eraseFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setActing(false);
        }
    };

    const { page, pageSize, setPage, setPageSize } = useRememberedPaging('admin.users');

    const visible = useMemo((): AdminUser[] => {
        const term = search.trim().toLowerCase();

        return (directory?.users ?? []).filter(
            (user) =>
                (term === '' || user.email.toLowerCase().includes(term)) &&
                (statusFilter === '' || user.status === statusFilter) &&
                (roleFilter === '' || user.roles.includes(roleFilter)),
        );
    }, [directory, search, statusFilter, roleFilter]);

    /*
     * Paged in the browser, over what the directory returned.
     *
     * Not on the server, because the server cannot help: an email is encrypted at rest, so it can
     * neither be searched nor sorted by SQL — which is why this screen loads the directory (capped
     * at 200, with `truncated` saying so) and filters here. Paging the same list keeps the filters
     * and the pager telling the same story; paging on the server would leave a search that only
     * ever looked at the current page.
     */
    const pageCount = Math.max(1, Math.ceil(visible.length / pageSize));
    const currentPage = Math.min(page, pageCount);
    const paged = visible.slice((currentPage - 1) * pageSize, currentPage * pageSize);

    const total = directory?.users.length ?? 0;
    const withMfa = (directory?.users ?? []).filter((user) => user.passkeys > 0 || user.totp).length;
    const admins = (directory?.users ?? []).filter((user) => user.roles.includes('SUPER_ADMIN')).length;

    const save = async (user: AdminUser, roles: string[], status: string): Promise<void> => {
        try {
            if (roles.join(',') !== user.roles.join(',')) {
                await updateUserRoles(user.id, roles);
            }

            if (status !== user.status) {
                await updateUserStatus(user.id, status);
            }

            toast.success(t((d) => d.pages.adminUsers.saved));
            setEditing(null);
            reload();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminUsers.saveFailed),
                { description: describeFailure(cause) },
            );
        }
    };

    if (forbidden) {
        return (
            <AppShell>
                <div className="mx-auto w-full max-w-lg pt-16 text-center">
                    <h1 className="text-lg font-semibold">{t((d) => d.pages.adminUsers.title)}</h1>
                    <p className="note mt-2">{t((d) => d.pages.adminUsers.forbidden)}</p>
                </div>
            </AppShell>
        );
    }

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.adminUsers.title)}
                lede={t((d) => d.pages.adminUsers.description)}
                actions={
                    failed ? (
                        <PanelButton onClick={reload}>{t((d) => d.pages.adminUsers.retry)}</PanelButton>
                    ) : undefined
                }
            >
                {/*
                    `Tabs`, as the reports screen uses: a tray with a mark on each, which at phone width reads
                    as one control rather than three words with an underline somewhere beneath them.
                */}
                <Tabs
                    className="tabs-fill"
                    value={tab}
                    onChange={setTab}
                    options={[
                        {
                            value: 'users',
                            label: t((d) => d.pages.adminUsers.tabs.users),
                            icon: <UsersIcon className="size-3.5" aria-hidden="true" />,
                        },
                        {
                            value: 'roles',
                            label: t((d) => d.pages.adminUsers.tabs.roles),
                            icon: <Shield className="size-3.5" aria-hidden="true" />,
                        },
                        {
                            value: 'permissions',
                            label: t((d) => d.pages.adminUsers.tabs.permissions),
                            icon: <KeyRound className="size-3.5" aria-hidden="true" />,
                        },
                    ]}
                />

                {tab === 'users' ? (
                    <div className="page-stack">
                        <StatGrid>
                            <Stat
                                icon={<UsersIcon className="size-4" />}
                                tone="accent"
                                label={t((d) => d.pages.adminUsers.stats.users)}
                                value={directory === null ? '—' : total}
                            />
                            <Stat
                                icon={<ShieldCheck className="size-4" />}
                                tone="neutral"
                                label={t((d) => d.pages.adminUsers.stats.admins)}
                                value={directory === null ? '—' : admins}
                                sub={t((d) => d.pages.adminUsers.stats.adminsSub)}
                            />
                            <Stat
                                icon={<Fingerprint className="size-4" />}
                                /* Good news only when everybody is covered: a green 40% would congratulate
                                   the installation on most of its accounts being unprotected. */
                                tone={directory !== null && total > 0 && withMfa === total ? 'success' : 'neutral'}
                                label={t((d) => d.pages.adminUsers.stats.mfa)}
                                value={
                                    directory === null || total === 0
                                        ? '—'
                                        : `${String(Math.round((withMfa / total) * 100))}%`
                                }
                                sub={
                                    directory === null
                                        ? undefined
                                        : t((d) => d.pages.adminUsers.stats.mfaSub)
                                              .replace('{covered}', String(withMfa))
                                              .replace('{total}', String(total))
                                }
                            />
                            <Stat
                                icon={<KeyRound className="size-4" />}
                                tone="neutral"
                                label={t((d) => d.pages.adminUsers.stats.permissions)}
                                value={
                                    access === null
                                        ? '—'
                                        : access.groups.reduce((count, group) => count + group.permissions.length, 0)
                                }
                                sub={t((d) => d.pages.adminUsers.stats.permissionsSub)}
                            />
                        </StatGrid>

                        <Panel
                            title={t((d) => d.pages.adminUsers.usersTitle)}
                            description={t((d) => d.pages.adminUsers.usersDescription)}
                            icon={<UsersIcon className="size-4" />}
                            flush
                            aside={
                                <PanelButton
                                    primary
                                    inline
                                    onClick={() => {
                                        setCreatingUser(true);
                                    }}
                                >
                                    <Plus className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.adminUsers.newButton)}
                                </PanelButton>
                            }
                            foot={
                                <Pagination
                                    page={currentPage}
                                    pageSize={pageSize}
                                    total={visible.length}
                                    unit={t((d) => d.pages.adminUsers.unit)}
                                    onPageChange={setPage}
                                    onPageSizeChange={(size) => {
                                        setPageSize(size);
                                        setPage(1);
                                    }}
                                />
                            }
                        >
                            <Toolbar>
                                <SearchInput
                                    value={search}
                                    placeholder={t((d) => d.pages.adminUsers.searchPlaceholder)}
                                    onChange={(next) => {
                                        setSearch(next);
                                        setPage(1);
                                    }}
                                />
                                <PanelSelect
                                    className="filter-select"
                                    icon={<Shield className="size-3.5" aria-hidden="true" />}
                                    value={roleFilter}
                                    onValueChange={(next) => {
                                        setRoleFilter(next);
                                        setPage(1);
                                    }}
                                    placeholder={t((d) => d.pages.adminUsers.allRoles)}
                                    emptyLabel={t((d) => d.pages.adminUsers.allRoles)}
                                    options={(access?.roles ?? []).map((role) => ({
                                        value: role,
                                        label: t((d) => d.pages.adminUsers.roles[role] ?? role),
                                    }))}
                                />
                                <PanelSelect
                                    className="filter-select"
                                    icon={<CircleDot className="size-3.5" aria-hidden="true" />}
                                    value={statusFilter}
                                    onValueChange={(next) => {
                                        setStatusFilter(next);
                                        setPage(1);
                                    }}
                                    placeholder={t((d) => d.pages.adminUsers.allStatuses)}
                                    emptyLabel={t((d) => d.pages.adminUsers.allStatuses)}
                                    options={['ACTIVE', 'SUSPENDED', 'DELETED'].map((value) => ({
                                        value,
                                        label: t((d) => d.pages.adminUsers.statuses[value] ?? value),
                                    }))}
                                />
                                <PanelButton
                                    onClick={() => {
                                        setSearch('');
                                        setRoleFilter('');
                                        setStatusFilter('');
                                        setPage(1);
                                    }}
                                >
                                    <RotateCcw className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.adminUsers.reset)}
                                </PanelButton>
                            </Toolbar>

                            {directory === null ? (
                                <div className="space-y-2 p-3">
                                    {[0, 1, 2].map((index) => (
                                        <Skeleton key={index} className="tile h-11 w-full" />
                                    ))}
                                </div>
                            ) : visible.length === 0 ? (
                                <EmptyState
                                    icon={<UserSearch className="size-5" />}
                                    title={t((d) => d.pages.adminUsers.empty)}
                                    description={t((d) => d.pages.adminUsers.emptyHint)}
                                    action={
                                        <PanelButton
                                            onClick={() => {
                                                setSearch('');
                                                setRoleFilter('');
                                                setStatusFilter('');
                                                setPage(1);
                                            }}
                                        >
                                            <FilterX className="size-3.5" aria-hidden="true" />
                                            {t((d) => d.pages.adminUsers.clearFilters)}
                                        </PanelButton>
                                    }
                                />
                            ) : (
                                <DataTable
                                    // Stacked on a phone: the identity takes the first line and the
                                    // rest flows under it, rather than scrolling six columns
                                    // sideways out of reach.
                                    stacked
                                    head={
                                        <>
                                            <th>{t((d) => d.pages.adminUsers.columns.user)}</th>
                                            <th>{t((d) => d.pages.adminUsers.columns.role)}</th>
                                            <th>{t((d) => d.pages.adminUsers.columns.status)}</th>
                                            <th>{t((d) => d.pages.adminUsers.columns.mfa)}</th>
                                            <th>{t((d) => d.pages.adminUsers.columns.memberships)}</th>
                                            <th>{t((d) => d.pages.adminUsers.columns.lastSeen)}</th>
                                            <th />
                                        </>
                                    }
                                >
                                    {paged.map((user) => (
                                        <tr key={user.id}>
                                            <td className="col-primary">
                                                <Identity
                                                    initials={initialsFor(user.email)}
                                                    name={user.email.split('@')[0] ?? user.email}
                                                    sub={user.email}
                                                />
                                            </td>
                                            <td>
                                                {user.roles.length === 0 ? (
                                                    <span className="text-muted-foreground">—</span>
                                                ) : (
                                                    <div className="flex flex-wrap gap-1">
                                                        {user.roles.map((role) => (
                                                            <Pill
                                                                key={role}
                                                                tone={role === 'SUPER_ADMIN' ? 'accent' : 'neutral'}
                                                            >
                                                                {t((d) => d.pages.adminUsers.roles[role] ?? role)}
                                                            </Pill>
                                                        ))}
                                                    </div>
                                                )}
                                            </td>
                                            <td>
                                                <Status tone={user.status === 'ACTIVE' ? 'ok' : 'warn'}>
                                                    {t((d) => d.pages.adminUsers.statuses[user.status] ?? user.status)}
                                                </Status>
                                            </td>
                                            <td>
                                                {user.passkeys === 0 && !user.totp ? (
                                                    <Pill tone="locked">{t((d) => d.pages.adminUsers.mfaNone)}</Pill>
                                                ) : (
                                                    <div className="flex flex-wrap gap-1">
                                                        {user.passkeys > 0 ? (
                                                            <Pill>
                                                                {t((d) => d.pages.adminUsers.mfaPasskeys).replace(
                                                                    '{count}',
                                                                    String(user.passkeys),
                                                                )}
                                                            </Pill>
                                                        ) : null}
                                                        {user.totp ? (
                                                            <Pill>{t((d) => d.pages.adminUsers.mfaTotp)}</Pill>
                                                        ) : null}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="col-hide tabular-nums">{user.memberships.length}</td>
                                            <td className="col-hide whitespace-nowrap">
                                                {user.lastSeenAt === null
                                                    ? t((d) => d.pages.adminUsers.never)
                                                    : new Date(user.lastSeenAt).toLocaleString()}
                                            </td>
                                            <td className="col-end">
                                                <div className="flex justify-end">
                                                    {/* An erased account has nothing left to act
                                                        on: no credentials to sign in with, no role
                                                        anyone could exercise, and no second
                                                        erasure to perform. The server refuses all
                                                        three; the row simply does not offer them. */}
                                                    {user.status === 'DELETED' ? null : (
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <button
                                                                    type="button"
                                                                    className="row-menu"
                                                                    aria-label={t((d) => d.common.actions)}
                                                                    disabled={acting}
                                                                >
                                                                    <EllipsisVertical className="size-4" />
                                                                </button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end" className="w-56">
                                                                <DropdownMenuItem
                                                                    onSelect={() => {
                                                                        setEditing(user);
                                                                    }}
                                                                >
                                                                    <Pencil className="size-4" />
                                                                    {t((d) => d.pages.adminUsers.edit)}
                                                                </DropdownMenuItem>

                                                                {/* Both of these act *as* or *on* a
                                                                person rather than on a record, so
                                                                they are a super administrator's to
                                                                take — and only on someone else. */}
                                                                {isSuperAdmin && user.id !== currentUserId ? (
                                                                    <>
                                                                        <DropdownMenuItem
                                                                            onSelect={() => {
                                                                                setImpersonating(user);
                                                                            }}
                                                                        >
                                                                            <LogIn className="size-4" />
                                                                            {t((d) => d.pages.adminUsers.loginAs)}
                                                                        </DropdownMenuItem>

                                                                        <DropdownMenuSeparator />

                                                                        <DropdownMenuItem
                                                                            variant="destructive"
                                                                            onSelect={() => {
                                                                                setErasing(user);
                                                                            }}
                                                                        >
                                                                            <Trash2 className="size-4" />
                                                                            {t((d) => d.pages.adminUsers.erase)}
                                                                        </DropdownMenuItem>
                                                                    </>
                                                                ) : null}
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </DataTable>
                            )}

                            {directory?.truncated === true ? (
                                <p className="note border-t p-3">{t((d) => d.pages.adminUsers.truncated)}</p>
                            ) : null}
                        </Panel>
                    </div>
                ) : null}

                {tab === 'roles' ? (
                    <div className="roles-grid">
                        {(access?.roles ?? []).map((role) => {
                            const held = (access?.groups ?? [])
                                .flatMap((group) => group.permissions)
                                .filter((row) => row.roles[role] === true);
                            const members = (directory?.users ?? []).filter((user) => user.roles.includes(role)).length;

                            return (
                                <Panel
                                    key={role}
                                    icon={roleIcon(role)}
                                    title={t((d) => d.pages.adminUsers.roles[role] ?? role)}
                                    description={t((d) => d.pages.adminUsers.roleHints[role] ?? '')}
                                    aside={
                                        <Pill tone={role === 'SUPER_ADMIN' ? 'accent' : 'neutral'}>
                                            {t((d) => d.pages.adminUsers.members).replace('{count}', String(members))}
                                        </Pill>
                                    }
                                    note={t((d) => d.pages.adminUsers.systemRole)}
                                >
                                    <div className="flex flex-wrap gap-1.5">
                                        {held.length === 0 ? (
                                            <span className="note">{t((d) => d.pages.adminUsers.noPermissions)}</span>
                                        ) : (
                                            held.map((row) => (
                                                <span key={row.permission} className="perm">
                                                    {row.permission}
                                                </span>
                                            ))
                                        )}
                                    </div>
                                </Panel>
                            );
                        })}
                    </div>
                ) : null}

                {tab === 'permissions' ? (
                    <Panel
                        icon={<KeyRound className="size-4" />}
                        title={t((d) => d.pages.adminUsers.matrixTitle)}
                        description={t((d) => d.pages.adminUsers.matrixDescription)}
                        flush
                        note={t((d) => d.pages.adminUsers.matrixLegend)}
                    >
                        {access === null ? (
                            <div className="space-y-2 p-3">
                                {[0, 1, 2].map((index) => (
                                    <Skeleton key={index} className="tile h-9 w-full" />
                                ))}
                            </div>
                        ) : (
                            <>
                                <DataTable
                                    className="perm-matrix"
                                    // Stacked on a phone: the identity takes the first line and the
                                    // rest flows under it, rather than scrolling six columns
                                    // sideways out of reach.
                                    stacked
                                    head={
                                        <>
                                            <th>{t((d) => d.pages.adminUsers.columns.permission)}</th>
                                            {access.roles.map((role) => (
                                                <th key={role} className="text-center">
                                                    {t((d) => d.pages.adminUsers.roles[role] ?? role)}
                                                </th>
                                            ))}
                                        </>
                                    }
                                >
                                    {access.groups.map((group) => (
                                        <>
                                            <tr key={group.group} className="matrix-group">
                                                <td colSpan={access.roles.length + 1}>
                                                    {t(
                                                        (d) =>
                                                            d.pages.adminUsers.permissionGroups[group.group] ??
                                                            group.group,
                                                    )}
                                                </td>
                                            </tr>
                                            {group.permissions.map((row) => (
                                                <tr key={row.permission}>
                                                    <td>
                                                        <span className="mono mono-strong">{row.permission}</span>
                                                        {row.enforced ? null : (
                                                            <div className="identity-sub">
                                                                {t((d) => d.pages.adminUsers.notEnforcedYet)}
                                                            </div>
                                                        )}
                                                    </td>
                                                    {access.roles.map((role) => (
                                                        <td key={role}>
                                                            <Check
                                                                granted={row.roles[role] === true}
                                                                enforced={row.enforced}
                                                            />
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </>
                                    ))}
                                </DataTable>
                            </>
                        )}
                    </Panel>
                ) : null}
            </Page>

            <NewUserDialog
                open={creatingUser}
                onOpenChange={setCreatingUser}
                roles={access?.roles ?? []}
                onCreated={reload}
            />

            <EditUserDialog
                user={editing}
                roles={access?.roles ?? []}
                onClose={() => {
                    setEditing(null);
                }}
                onSave={save}
            />

            {/* Both say plainly what will happen, because neither is obvious from its name: one
                hands an administrator somebody else's encrypted data, the other keeps a trail it
                cannot erase. */}
            {impersonating !== null && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setImpersonating(null);
                        }
                    }}
                    title={t((d) => d.pages.adminUsers.loginAsTitle)}
                    description={t((d) => d.pages.adminUsers.loginAsHint).replace('{email}', impersonating.email)}
                    confirmLabel={t((d) => d.pages.adminUsers.loginAs)}
                    cancelLabel={t((d) => d.pages.adminUsers.cancel)}
                    onConfirm={async () => {
                        await startImpersonation(impersonating);
                    }}
                />
            )}

            {erasing !== null && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setErasing(null);
                        }
                    }}
                    title={t((d) => d.pages.adminUsers.eraseTitle)}
                    description={t((d) => d.pages.adminUsers.eraseHint).replace('{email}', erasing.email)}
                    detail={<p className="note">{t((d) => d.pages.adminUsers.eraseAuditNote)}</p>}
                    confirmLabel={t((d) => d.pages.adminUsers.erase)}
                    cancelLabel={t((d) => d.pages.adminUsers.cancel)}
                    onConfirm={async () => {
                        await erase(erasing);
                    }}
                />
            )}
        </AppShell>
    );
}

/** Edit drawer: platform roles and account status, the two things this screen can actually change. */
/**
 * Editing a user, in the same dialog every other screen uses.
 *
 * It was a right-hand drawer, which is a different shape for the same job — a short form with two
 * choices and two buttons. One modal vocabulary means a person learns where Cancel lives once.
 */
function EditUserDialog(props: {
    user: AdminUser | null;
    roles: string[];
    onClose: () => void;
    onSave: (user: AdminUser, roles: string[], status: string) => Promise<void>;
}): JSX.Element | null {
    const { t } = useTranslation();
    const user = props.user;
    const [draft, setDraft] = useState<{ role: string; status: string } | null>(null);
    const [saving, setSaving] = useState(false);

    if (user === null) {
        return null;
    }

    const role = draft?.role ?? user.roles[0] ?? '';
    const status = draft?.status ?? user.status;

    return (
        <PanelDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    props.onClose();
                }
            }}
            title={t((d) => d.pages.adminUsers.editTitle)}
            actions={
                <>
                    <PanelButton variant="outline" onClick={props.onClose}>
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.common.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={saving}
                        onClick={() => {
                            setSaving(true);
                            void props.onSave(user, role === '' ? [] : [role], status).finally(() => {
                                setSaving(false);
                            });
                        }}
                    >
                        <CheckIcon className="size-3.5" aria-hidden="true" />
                        {saving ? t((d) => d.pages.adminUsers.saving) : t((d) => d.common.save)}
                    </PanelButton>
                </>
            }
        >
            {/* Keyed on the user: opening a different row discards this form's state rather than
                re-seeding it from an effect, which React flags as a cascading render. */}
            <EditUserForm
                key={user.id}
                user={user}
                roles={props.roles}
                role={role}
                status={status}
                onChange={(next) => {
                    setDraft(next);
                }}
            />
        </PanelDialog>
    );
}

function EditUserForm(props: {
    user: AdminUser;
    roles: string[];
    role: string;
    status: string;
    onChange: (next: { role: string; status: string }) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const user = props.user;

    return (
        <div className="space-y-4">
            <Identity
                initials={initialsFor(user.email)}
                name={user.email.split('@')[0] ?? user.email}
                sub={user.email}
            />

            <FieldGrid>
                <Field label={t((d) => d.pages.adminUsers.columns.role)}>
                    <PanelSelect
                        value={props.role}
                        onValueChange={(next) => {
                            props.onChange({ role: next, status: props.status });
                        }}
                        placeholder={t((d) => d.pages.adminUsers.noRole)}
                        options={props.roles.map((value) => ({
                            value,
                            label: t((d) => d.pages.adminUsers.roles[value] ?? value),
                        }))}
                    />
                </Field>

                <Field label={t((d) => d.pages.adminUsers.columns.status)}>
                    <PanelSelect
                        value={props.status}
                        onValueChange={(next) => {
                            props.onChange({ role: props.role, status: next });
                        }}
                        options={['ACTIVE', 'SUSPENDED'].map((value) => ({
                            value,
                            label: t((d) => d.pages.adminUsers.statuses[value] ?? value),
                        }))}
                    />
                </Field>
            </FieldGrid>

            <p className="note">{t((d) => d.pages.adminUsers.editNotice)}</p>
        </div>
    );
}
