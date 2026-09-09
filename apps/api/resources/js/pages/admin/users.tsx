import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { getAccessCatalogue, getAdminDirectory, updateUserRoles, updateUserStatus } from '../../api/adminApi';
import AppShell from '../../components/AppShell';
import {
    Check,
    DataTable,
    Identity,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    SearchInput,
    Stat,
    StatGrid,
    Status,
    TabRail,
    Toolbar,
} from '../../components/panel';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { ApiError } from '../../lib/http';
import type { AccessCatalogue, AdminDirectory, AdminUser } from '../../types/admin';

type Tab = 'users' | 'roles' | 'permissions';

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
export default function AdminUsers(): JSX.Element {
    const { t } = useTranslation();

    const [tab, setTab] = useState<Tab>('users');
    const [directory, setDirectory] = useState<AdminDirectory | null>(null);
    const [access, setAccess] = useState<AccessCatalogue | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [failed, setFailed] = useState(false);
    const [search, setSearch] = useState('');
    const [roleFilter, setRoleFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [editing, setEditing] = useState<AdminUser | null>(null);
    const [reloadToken, setReloadToken] = useState(0);

    const describeFailure = useCallback(
        (cause: unknown): string => {
            if (cause instanceof ApiError) {
                if (cause.reason === 'network') {
                    return t((d) => d.errors.network);
                }

                const first = Object.values(cause.errors ?? {})[0]?.[0];

                if (first !== undefined) {
                    return first;
                }

                // 422 from the lockout guards carries its explanation in `message`, and it is the
                // whole point of the response — "you cannot demote the last super administrator".
                if (cause.status === 422 && cause.message !== '') {
                    return cause.message;
                }
            }

            return t((d) => d.errors.unexpected);
        },
        [t],
    );

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

    const visible = useMemo((): AdminUser[] => {
        const term = search.trim().toLowerCase();

        return (directory?.users ?? []).filter(
            (user) =>
                (term === '' || user.email.toLowerCase().includes(term)) &&
                (statusFilter === '' || user.status === statusFilter) &&
                (roleFilter === '' || user.roles.includes(roleFilter)),
        );
    }, [directory, search, statusFilter, roleFilter]);

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
            <div>
                <header className="page-head">
                    <div>
                        <h1 className="page-title">{t((d) => d.pages.adminUsers.title)}</h1>
                        <p className="page-lede max-w-2xl">{t((d) => d.pages.adminUsers.description)}</p>
                    </div>

                    {failed ? <PanelButton onClick={reload}>{t((d) => d.pages.adminUsers.retry)}</PanelButton> : null}
                </header>

                <TabRail
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { value: 'users', label: t((d) => d.pages.adminUsers.tabs.users) },
                        { value: 'roles', label: t((d) => d.pages.adminUsers.tabs.roles) },
                        { value: 'permissions', label: t((d) => d.pages.adminUsers.tabs.permissions) },
                    ]}
                />

                {tab === 'users' ? (
                    <div className="page-stack">
                        <StatGrid>
                            <Stat
                                label={t((d) => d.pages.adminUsers.stats.users)}
                                value={directory === null ? '—' : total}
                            />
                            <Stat
                                label={t((d) => d.pages.adminUsers.stats.admins)}
                                value={directory === null ? '—' : admins}
                                sub={t((d) => d.pages.adminUsers.stats.adminsSub)}
                            />
                            <Stat
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
                                label={t((d) => d.pages.adminUsers.stats.permissions)}
                                value={
                                    access === null
                                        ? '—'
                                        : access.groups.reduce((count, group) => count + group.permissions.length, 0)
                                }
                                sub={t((d) => d.pages.adminUsers.stats.permissionsSub)}
                            />
                        </StatGrid>

                        <Panel title={t((d) => d.pages.adminUsers.usersTitle)} flush>
                            <Toolbar>
                                <SearchInput
                                    value={search}
                                    placeholder={t((d) => d.pages.adminUsers.searchPlaceholder)}
                                    onChange={setSearch}
                                />
                                <PanelSelect
                                    className="filter-select"
                                    value={roleFilter}
                                    onValueChange={setRoleFilter}
                                    placeholder={t((d) => d.pages.adminUsers.allRoles)}
                                    emptyLabel={t((d) => d.pages.adminUsers.allRoles)}
                                    options={(access?.roles ?? []).map((role) => ({
                                        value: role,
                                        label: t((d) => d.pages.adminUsers.roles[role] ?? role),
                                    }))}
                                />
                                <PanelSelect
                                    className="filter-select"
                                    value={statusFilter}
                                    onValueChange={setStatusFilter}
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
                                    }}
                                >
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
                                <p className="note p-4">{t((d) => d.pages.adminUsers.empty)}</p>
                            ) : (
                                <DataTable
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
                                    {visible.map((user) => (
                                        <tr key={user.id}>
                                            <td>
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
                                            <td className="tabular-nums">{user.memberships.length}</td>
                                            <td className="whitespace-nowrap">
                                                {user.lastSeenAt === null
                                                    ? t((d) => d.pages.adminUsers.never)
                                                    : new Date(user.lastSeenAt).toLocaleString()}
                                            </td>
                                            <td className="text-right">
                                                <PanelButton
                                                    onClick={() => {
                                                        setEditing(user);
                                                    }}
                                                >
                                                    {t((d) => d.pages.adminUsers.edit)}
                                                </PanelButton>
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
                                    title={t((d) => d.pages.adminUsers.roles[role] ?? role)}
                                    description={t((d) => d.pages.adminUsers.roleHints[role] ?? '')}
                                    aside={
                                        <Pill tone={role === 'SUPER_ADMIN' ? 'accent' : 'neutral'}>
                                            {t((d) => d.pages.adminUsers.members).replace('{count}', String(members))}
                                        </Pill>
                                    }
                                    footNote={t((d) => d.pages.adminUsers.systemRole)}
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
                        title={t((d) => d.pages.adminUsers.matrixTitle)}
                        description={t((d) => d.pages.adminUsers.matrixDescription)}
                        flush
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
                                <p className="note border-t p-3">{t((d) => d.pages.adminUsers.matrixLegend)}</p>
                            </>
                        )}
                    </Panel>
                ) : null}
            </div>

            <EditUserSheet
                user={editing}
                roles={access?.roles ?? []}
                onClose={() => {
                    setEditing(null);
                }}
                onSave={save}
            />
        </AppShell>
    );
}

/** Edit drawer: platform roles and account status, the two things this screen can actually change. */
function EditUserSheet(props: {
    user: AdminUser | null;
    roles: string[];
    onClose: () => void;
    onSave: (user: AdminUser, roles: string[], status: string) => Promise<void>;
}): JSX.Element {
    const { t } = useTranslation();
    const user = props.user;

    return (
        <Sheet
            open={user !== null}
            onOpenChange={(open) => {
                if (!open) {
                    props.onClose();
                }
            }}
        >
            <SheetContent side="right" className="drawer gap-0 p-0">
                <SheetHeader className="border-b p-4">
                    <SheetTitle className="dialog-title">{t((d) => d.pages.adminUsers.editTitle)}</SheetTitle>
                </SheetHeader>

                {user === null ? null : (
                    // Keyed on the user: opening a different row discards this form's state rather
                    // than re-seeding it from an effect, which React flags as a cascading render.
                    <EditUserForm
                        key={user.id}
                        user={user}
                        roles={props.roles}
                        onClose={props.onClose}
                        onSave={props.onSave}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function EditUserForm(props: {
    user: AdminUser;
    roles: string[];
    onClose: () => void;
    onSave: (user: AdminUser, roles: string[], status: string) => Promise<void>;
}): JSX.Element {
    const { t } = useTranslation();
    const user = props.user;
    const [role, setRole] = useState(user.roles[0] ?? '');
    const [status, setStatus] = useState(user.status);
    const [saving, setSaving] = useState(false);

    return (
        <div className="space-y-4 overflow-auto p-4">
            <Identity
                initials={initialsFor(user.email)}
                name={user.email.split('@')[0] ?? user.email}
                sub={user.email}
            />

            <div>
                <div className="field-label-row">
                    <span className="field-label">{t((d) => d.pages.adminUsers.columns.role)}</span>
                </div>
                <PanelSelect
                    value={role}
                    onValueChange={setRole}
                    placeholder={t((d) => d.pages.adminUsers.noRole)}
                    options={props.roles.map((value) => ({
                        value,
                        label: t((d) => d.pages.adminUsers.roles[value] ?? value),
                    }))}
                />
            </div>

            <div>
                <div className="field-label-row">
                    <span className="field-label">{t((d) => d.pages.adminUsers.columns.status)}</span>
                </div>
                <PanelSelect
                    value={status}
                    onValueChange={setStatus}
                    options={['ACTIVE', 'SUSPENDED'].map((value) => ({
                        value,
                        label: t((d) => d.pages.adminUsers.statuses[value] ?? value),
                    }))}
                />
            </div>

            <p className="note">{t((d) => d.pages.adminUsers.editNotice)}</p>

            <div className="flex justify-end gap-1.5">
                <PanelButton variant="outline" onClick={props.onClose}>
                    {t((d) => d.pages.adminUsers.cancel)}
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
                    {saving ? t((d) => d.pages.adminUsers.saving) : t((d) => d.pages.adminUsers.save)}
                </PanelButton>
            </div>
        </div>
    );
}
