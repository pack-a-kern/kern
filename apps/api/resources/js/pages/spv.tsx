import { useCallback, useEffect, useState, type JSX } from 'react';
import {
    Activity,
    Ban,
    Clock,
    KeyRound,
    Link2,
    Link2Off,
    Mails,
    RefreshCw,
    ShieldCheck,
    Trash2,
    UserRound,
    X,
} from 'lucide-react';
import { toast } from 'sonner';

import AppShell from '../components/AppShell';
import {
    CellLabel,
    DataTable,
    EmptyState,
    Field,
    FieldGrid,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelSelect,
    Status,
    TableSkeleton,
} from '../components/panel';
import { useFormErrors } from '../hooks/useFormErrors';
import { useTranslation } from '../i18n';
import { getActiveSubjectId } from '../lib/subjectStore';
import { formatDateTime } from '../lib/format';
import { reportApiFailure } from '../lib/apiErrors';
import { apiRequest } from '../lib/http';
import { obj, str } from '../lib/json';

/** What the server says a connection is. Mirrors `ShowSpvConnectionsController`. */
interface Connection {
    id: string;
    authMethod: string;
    username: string | null;
    status: string;
    lastSuccessAt: string | null;
    /**
     * What ANAF said when it refused, in its own words.
     *
     * Shown rather than translated: the portal is the only thing that knows *which* credential was
     * wrong, or that an account is locked rather than mistyped, and paraphrasing it into "sign-in
     * failed" throws away the one detail the person needed.
     */
    lastErrorMessage: string | null;
    lastRun: { status: string; startedAt: string; messageCount: number | null } | null;
    /**
     * How many times this connection has been used.
     *
     * Decides which endings the row offers: revoking preserves a history, so it is only worth
     * offering once there is one. Deleting is always offered, and says what it will destroy.
     */
    runCount: number;
    /**
     * The signed-in browser behind this connection, if one is open.
     *
     * Reading the portal happens in the browser the sign-in left open, so without one there is nothing
     * to read with and "Check inbox" is not offered. Null for a certificate connection too, which needs
     * no sitting — it authenticates every request on its own — so that case is told apart by
     * `authMethod` rather than by this being absent.
     */
    session: { expiresAt: string } | null;
}

/**
 * Where a sign-in has got to.
 *
 * The three the portal itself reports, which are also the three this screen has to draw: ask for
 * the password, ask for the emailed code, or say what went wrong.
 */
type SignIn =
    | { step: 'closed' }
    | { step: 'credentials'; connection: Connection; sessionId: string }
    | { step: 'challenge'; connection: Connection; sessionId: string };

function parse(payload: unknown): Connection[] {
    const body = obj(payload);

    return (Array.isArray(body.connections) ? body.connections : []).map((entry): Connection => {
        const row = obj(entry);
        const run = obj(row.last_run);

        return {
            id: str(row, 'id'),
            authMethod: str(row, 'auth_method'),
            username: typeof row.username === 'string' ? row.username : null,
            status: str(row, 'status'),
            lastSuccessAt: typeof row.last_success_at === 'string' ? row.last_success_at : null,
            lastErrorMessage: typeof row.last_error_message === 'string' ? row.last_error_message : null,
            runCount: typeof row.run_count === 'number' ? row.run_count : 0,
            session:
                str(obj(row.session), 'expires_at') === '' ? null : { expiresAt: str(obj(row.session), 'expires_at') },
            lastRun:
                row.last_run === null || row.last_run === undefined
                    ? null
                    : {
                          status: str(run, 'status'),
                          startedAt: str(run, 'started_at'),
                          messageCount: typeof run.message_count === 'number' ? run.message_count : null,
                      },
        };
    });
}

/**
 * SPV — the taxpayer's inbox at ANAF.
 *
 * ## Why signing in is three steps a person sits through
 *
 * The portal answers a username and password with a code it emails, and the browser that asked has
 * to still be open when the code arrives. So the screen opens a session, sends the credentials,
 * then sends the code, and the worker holds a real browser across all three.
 *
 * Nothing is remembered between syncs. `SpvCredentialPolicy` has exactly one case and it forbids
 * storing the password in any form, so the next check of the inbox asks again — which is also why
 * this cannot run on a schedule, unlike a certificate connection, where the certificate does the
 * authenticating on every request with nobody present.
 */
export default function Spv(): JSX.Element {
    const { t, locale } = useTranslation();
    const [subjectId] = useState(() => getActiveSubjectId());
    const [connections, setConnections] = useState<Connection[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloads, setReloads] = useState(0);

    const [connecting, setConnecting] = useState(false);
    const [method, setMethod] = useState('USER_PASSWORD_OTP');
    const [username, setUsername] = useState('');

    const [signIn, setSignIn] = useState<SignIn>({ step: 'closed' });
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [working, setWorking] = useState(false);
    const errors = useFormErrors();
    const [revoking, setRevoking] = useState<Connection | null>(null);
    const [deleting, setDeleting] = useState<Connection | null>(null);

    const base = subjectId === null ? null : `/v1/subjects/${encodeURIComponent(subjectId)}/spv`;

    useEffect(() => {
        if (base === null) {
            return;
        }

        let active = true;

        apiRequest(`${base}/connections`).then(
            (text: string) => {
                if (active) {
                    setConnections(parse(JSON.parse(text)));
                    setFailed(false);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [base, reloads]);

    const reload = useCallback(() => {
        setReloads((current) => current + 1);
    }, []);

    /* Cleared together, and on every exit from the dialog: a password left in state is a password
       the next person to open this screen would be typing over rather than typing. */
    const clearSecrets = (): void => {
        setPassword('');
        setCode('');
    };

    const closeSignIn = (session: string | null): void => {
        clearSecrets();
        setSignIn({ step: 'closed' });

        if (session !== null && base !== null) {
            // The browser on the worker outlives this dialog unless it is told not to. Failing to
            // close it is not worth reporting — it expires on its own — but leaving it is wasteful.
            void apiRequest(`${base}/sessions/${encodeURIComponent(session)}`, { method: 'DELETE' }).catch(() => {});
        }
    };

    const sync = async (connection: Connection): Promise<void> => {
        if (base === null) {
            return;
        }

        setWorking(true);

        try {
            const text = await apiRequest(`${base}/connections/${encodeURIComponent(connection.id)}/sync`, {
                method: 'POST',
            });
            const body = obj(JSON.parse(text));
            const count = typeof body.message_count === 'number' ? body.message_count : 0;

            if (str(body, 'status') === 'SUCCEEDED') {
                toast.success(t((d) => d.pages.spv.syncDone).replace('{count}', String(count)));
            } else {
                toast.error(t((d) => d.pages.spv.syncFailed));
            }
        } catch (cause: unknown) {
            reportApiFailure(cause, t);
        } finally {
            setWorking(false);
            reload();
        }
    };

    const startSignIn = async (connection: Connection): Promise<void> => {
        if (base === null) {
            return;
        }

        setWorking(true);

        try {
            const text = await apiRequest(`${base}/connections/${encodeURIComponent(connection.id)}/session`, {
                method: 'POST',
            });
            const body = obj(JSON.parse(text));

            setUsername(connection.username ?? '');
            setSignIn({ step: 'credentials', connection, sessionId: str(body, 'session_id') });
        } catch (cause: unknown) {
            reportApiFailure(cause, t);
        } finally {
            setWorking(false);
        }
    };

    const sendCredentials = async (): Promise<void> => {
        if (base === null || signIn.step !== 'credentials') {
            return;
        }

        setWorking(true);

        try {
            const text = await apiRequest(`${base}/sessions/${encodeURIComponent(signIn.sessionId)}/credentials`, {
                method: 'POST',
                body: { username, password },
            });

            // Cleared the moment it has been sent, not when the dialog closes.
            setPassword('');

            const state = str(obj(JSON.parse(text)), 'state');

            if (state === 'AWAITING_USER_CHALLENGE') {
                setSignIn({ ...signIn, step: 'challenge' });
            } else if (state === 'AUTHENTICATED') {
                const connection = signIn.connection;
                closeSignIn(null);
                toast.success(t((d) => d.pages.spv.signedIn));
                await sync(connection);
            } else {
                toast.error(t((d) => d.pages.spv.signInFailed));
            }
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                reportApiFailure(cause, t);
            }
        } finally {
            setWorking(false);
        }
    };

    const sendCode = async (): Promise<void> => {
        if (base === null || signIn.step !== 'challenge') {
            return;
        }

        setWorking(true);

        try {
            const text = await apiRequest(`${base}/sessions/${encodeURIComponent(signIn.sessionId)}/challenge`, {
                method: 'POST',
                body: { code },
            });

            setCode('');

            if (str(obj(JSON.parse(text)), 'state') === 'AUTHENTICATED') {
                const connection = signIn.connection;
                closeSignIn(null);
                toast.success(t((d) => d.pages.spv.signedIn));
                await sync(connection);
            } else {
                toast.error(t((d) => d.pages.spv.signInFailed));
            }
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                reportApiFailure(cause, t);
            }
        } finally {
            setWorking(false);
        }
    };

    const revoke = async (connection: Connection): Promise<void> => {
        if (base === null) {
            return;
        }

        setWorking(true);

        try {
            await apiRequest(`${base}/connections/${encodeURIComponent(connection.id)}/revocation`, {
                method: 'POST',
            });
            setRevoking(null);
            reload();
        } catch (cause: unknown) {
            reportApiFailure(cause, t);
        } finally {
            setWorking(false);
        }
    };

    const remove = async (connection: Connection): Promise<void> => {
        if (base === null) {
            return;
        }

        setWorking(true);

        try {
            await apiRequest(`${base}/connections/${encodeURIComponent(connection.id)}`, { method: 'DELETE' });
            setDeleting(null);
            reload();
        } catch (cause: unknown) {
            reportApiFailure(cause, t);
        } finally {
            setWorking(false);
        }
    };

    const register = async (): Promise<void> => {
        if (base === null) {
            return;
        }

        setWorking(true);

        try {
            await apiRequest(`${base}/connections`, {
                method: 'POST',
                body: { auth_method: method, username: method === 'USER_PASSWORD_OTP' ? username : null },
            });

            setConnecting(false);
            setUsername('');
            errors.reset();
            reload();
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                reportApiFailure(cause, t);
            }
        } finally {
            setWorking(false);
        }
    };

    const statusTone = (status: string): 'ok' | 'warn' | 'bad' | 'idle' =>
        status === 'ACTIVE' ? 'ok' : status === 'FAILED' ? 'bad' : status === 'REVOKED' ? 'idle' : 'warn';

    return (
        <AppShell>
            <Page title={t((d) => d.pages.spv.title)} lede={t((d) => d.pages.spv.description)}>
                {failed ? <p className="note">{t((d) => d.pages.spv.syncFailed)}</p> : null}

                <Panel
                    title={t((d) => d.pages.spv.connections)}
                    description={t((d) => d.pages.spv.connectionsHint)}
                    icon={<Mails className="size-4" />}
                    flush
                    aside={
                        <PanelButton
                            primary
                            onClick={() => {
                                errors.reset();
                                setConnecting(true);
                            }}
                        >
                            <Link2 className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.connect)}
                        </PanelButton>
                    }
                >
                    {connections === null ? (
                        /* The shape of what is coming, like every other list in the app — a line of
                           grey text that is replaced a moment later reads as a flicker rather than
                           as loading. Two rows: an account has one SPV connection, rarely two. */
                        <TableSkeleton rows={2} />
                    ) : connections.length === 0 ? (
                        <EmptyState
                            icon={<Link2Off className="size-5" />}
                            title={t((d) => d.pages.spv.noConnections)}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.spv.method)}</th>
                                    <th>{t((d) => d.pages.spv.lastSuccess)}</th>
                                    <th>{t((d) => d.pages.spv.statusColumn)}</th>
                                    <th />
                                </>
                            }
                        >
                            {connections.map((connection) => (
                                <tr key={connection.id}>
                                    <td className="col-primary">
                                        <div className="flex items-center gap-2 font-semibold">
                                            {connection.authMethod === 'CERTIFICATE' ? (
                                                <ShieldCheck className="text-muted-foreground size-3.5" />
                                            ) : (
                                                <UserRound className="text-muted-foreground size-3.5" />
                                            )}
                                            {t(
                                                (d) =>
                                                    d.pages.spv.methods[connection.authMethod] ?? connection.authMethod,
                                            )}
                                        </div>
                                        {connection.username === null || connection.username === '' ? null : (
                                            <div className="note">{connection.username}</div>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap">
                                        <CellLabel icon={<Clock className="size-3.5" aria-hidden="true" />}>
                                            {t((d) => d.pages.spv.lastSuccess)}
                                        </CellLabel>
                                        {connection.lastSuccessAt === null ? (
                                            <span className="note">{t((d) => d.pages.spv.never)}</span>
                                        ) : (
                                            <time dateTime={connection.lastSuccessAt} className="tabular-nums">
                                                {formatDateTime(connection.lastSuccessAt, locale)}
                                            </time>
                                        )}
                                    </td>
                                    <td>
                                        <CellLabel icon={<Activity className="size-3.5" aria-hidden="true" />}>
                                            {t((d) => d.pages.spv.statusColumn)}
                                        </CellLabel>
                                        <Status tone={statusTone(connection.status)}>
                                            {t((d) => d.pages.spv.statuses[connection.status] ?? connection.status)}
                                        </Status>
                                        {/* Beneath the badge rather than instead of it: the badge says
                                            what state this is in, and this says why. Romanian whatever
                                            the interface language, because it is a quotation. */}
                                        {/* The badge is the connection's history — has this ever
                                            worked. This is whether a browser is signed in *now*, which
                                            is a different fact and the one that decides what can be
                                            done: reading the portal happens inside that sitting, and it
                                            ends on its own. */}
                                        {connection.session === null ? null : (
                                            <p className="note mt-1">
                                                {t((d) => d.pages.spv.signedInUntil).replace(
                                                    '{time}',
                                                    formatDateTime(connection.session.expiresAt, locale),
                                                )}
                                            </p>
                                        )}
                                        {connection.lastErrorMessage === null ? null : (
                                            <p className="note text-destructive mt-1" lang="ro">
                                                {connection.lastErrorMessage}
                                            </p>
                                        )}
                                    </td>
                                    {/* Two labelled buttons do not fit the corner a pinned cell gets,
                                        so they take the card's last line on a phone. */}
                                    <td className="col-actions text-right">
                                        <div className="flex flex-wrap justify-end gap-1.5">
                                            {/* Offered while there is nothing to sign in *with*. A live
                                                session means a browser is open and signed in, so
                                                offering it again would start a second sitting and ask
                                                for a second emailed code to replace one that works. */}
                                            {connection.status === 'REVOKED' ||
                                            connection.authMethod === 'CERTIFICATE' ||
                                            connection.session !== null ? null : (
                                                <PanelButton
                                                    disabled={working}
                                                    onClick={() => {
                                                        void startSignIn(connection);
                                                    }}
                                                >
                                                    <KeyRound className="size-3.5" aria-hidden="true" />
                                                    {t((d) => d.pages.spv.signIn)}
                                                </PanelButton>
                                            )}
                                            {/* Reading the portal happens in the browser the sign-in
                                                left open, so the action exists only while one does.
                                                A certificate connection needs no sitting — it
                                                authenticates every request on its own — so it keeps
                                                the action unconditionally. When a sitting ends, this
                                                disappears rather than failing when pressed. */}
                                            {connection.status === 'REVOKED' ||
                                            (connection.authMethod !== 'CERTIFICATE' &&
                                                connection.session === null) ? null : (
                                                <PanelButton
                                                    disabled={working}
                                                    onClick={() => {
                                                        void sync(connection);
                                                    }}
                                                >
                                                    <RefreshCw className="size-3.5" aria-hidden="true" />
                                                    {t((d) => d.pages.spv.sync)}
                                                </PanelButton>
                                            )}
                                            {/* Revoking is only offered where there is something to
                                                preserve. A connection that never ran has no history,
                                                so leaving it in the list marked "revoked" for ever
                                                answers a question nobody asked — deleting is the
                                                only sensible ending for it. */}
                                            {connection.status === 'REVOKED' || connection.runCount === 0 ? null : (
                                                <PanelButton
                                                    disabled={working}
                                                    onClick={() => {
                                                        setRevoking(connection);
                                                    }}
                                                >
                                                    <Ban className="size-3.5" aria-hidden="true" />
                                                    {t((d) => d.pages.spv.revoke)}
                                                </PanelButton>
                                            )}
                                            <PanelButton
                                                disabled={working}
                                                onClick={() => {
                                                    setDeleting(connection);
                                                }}
                                            >
                                                <Trash2 className="text-destructive size-3.5" aria-hidden="true" />
                                                {t((d) => d.pages.spv.delete)}
                                            </PanelButton>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            <PanelDialog
                open={connecting}
                onOpenChange={(next) => {
                    if (!next) {
                        setConnecting(false);
                        errors.reset();
                    }
                }}
                title={t((d) => d.pages.spv.connectTitle)}
                description={t((d) => d.pages.spv.connectHint)}
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setConnecting(false);
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={working}
                            onClick={() => {
                                void register();
                            }}
                        >
                            <Link2 className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.connect)}
                        </PanelButton>
                    </>
                }
            >
                <FieldGrid>
                    <Field label={t((d) => d.pages.spv.method)} htmlFor="spv-method">
                        <PanelSelect
                            id="spv-method"
                            value={method}
                            icon={<ShieldCheck className="size-3.5" aria-hidden="true" />}
                            onValueChange={setMethod}
                            options={['USER_PASSWORD_OTP', 'CERTIFICATE'].map((value) => ({
                                value,
                                label: t((d) => d.pages.spv.methods[value] ?? value),
                            }))}
                        />
                    </Field>

                    {method === 'CERTIFICATE' ? (
                        <p className="note">{t((d) => d.pages.spv.certificateHint)}</p>
                    ) : (
                        <Field
                            label={t((d) => d.pages.spv.username)}
                            htmlFor="spv-username"
                            error={errors.get('username')}
                        >
                            <PanelInput
                                id="spv-username"
                                value={username}
                                autoComplete="off"
                                icon={<UserRound className="size-3.5" />}
                                onChange={(event) => {
                                    setUsername(event.target.value);
                                }}
                            />
                        </Field>
                    )}
                </FieldGrid>
            </PanelDialog>

            <PanelDialog
                open={revoking !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRevoking(null);
                    }
                }}
                title={t((d) => d.pages.spv.revokeTitle)}
                description={t((d) => d.pages.spv.revokeHint)}
                size="sm"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setRevoking(null);
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={working}
                            onClick={() => {
                                if (revoking !== null) {
                                    void revoke(revoking);
                                }
                            }}
                        >
                            <Ban className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.revoke)}
                        </PanelButton>
                    </>
                }
            >
                <p className="note">{revoking?.username ?? ''}</p>
            </PanelDialog>

            <PanelDialog
                open={deleting !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setDeleting(null);
                    }
                }}
                title={t((d) => d.pages.spv.deleteTitle)}
                description={t((d) => d.pages.spv.deleteHint)}
                size="sm"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setDeleting(null);
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={working}
                            onClick={() => {
                                if (deleting !== null) {
                                    void remove(deleting);
                                }
                            }}
                        >
                            <Trash2 className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.delete)}
                        </PanelButton>
                    </>
                }
            >
                <p className="note">{deleting?.username ?? ''}</p>
                {/* Named, not implied: the sync history cascades, and nobody should lose a year of it
                    without being told how much there was. */}
                {deleting !== null && deleting.runCount > 0 ? (
                    <p className="note text-destructive">
                        {t((d) => d.pages.spv.deleteRuns).replace('{count}', String(deleting.runCount))}
                    </p>
                ) : null}
            </PanelDialog>

            {/* One dialog for both steps: it is one sitting, and the browser on the worker stays open
                across it. Closing it at any point destroys that browser rather than leaving it on a
                half-authenticated page. */}
            <PanelDialog
                open={signIn.step !== 'closed'}
                onOpenChange={(next) => {
                    if (!next && signIn.step !== 'closed') {
                        closeSignIn(signIn.sessionId);
                    }
                }}
                title={t((d) => d.pages.spv.signInTitle)}
                description={
                    signIn.step === 'challenge'
                        ? t((d) => d.pages.spv.challengeStep)
                        : t((d) => d.pages.spv.credentialsStep)
                }
                size="sm"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                if (signIn.step !== 'closed') {
                                    closeSignIn(signIn.sessionId);
                                }
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={working}
                            onClick={() => {
                                void (signIn.step === 'challenge' ? sendCode() : sendCredentials());
                            }}
                        >
                            <KeyRound className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.spv.submit)}
                        </PanelButton>
                    </>
                }
            >
                {signIn.step === 'challenge' ? (
                    <Field label={t((d) => d.pages.spv.code)} htmlFor="spv-code" error={errors.get('code')}>
                        <PanelInput
                            id="spv-code"
                            value={code}
                            autoComplete="one-time-code"
                            inputMode="numeric"
                            icon={<KeyRound className="size-3.5" />}
                            onChange={(event) => {
                                setCode(event.target.value);
                            }}
                        />
                    </Field>
                ) : (
                    <FieldGrid>
                        <Field
                            label={t((d) => d.pages.spv.username)}
                            htmlFor="spv-signin-username"
                            error={errors.get('username')}
                        >
                            <PanelInput
                                id="spv-signin-username"
                                value={username}
                                autoComplete="off"
                                icon={<UserRound className="size-3.5" />}
                                onChange={(event) => {
                                    setUsername(event.target.value);
                                }}
                            />
                        </Field>
                        <Field
                            label={t((d) => d.pages.spv.password)}
                            htmlFor="spv-password"
                            error={errors.get('password')}
                        >
                            <PanelInput
                                id="spv-password"
                                type="password"
                                value={password}
                                // Never offered to a password manager: it is not stored here, and
                                // a browser remembering it would undo the one guarantee this has.
                                autoComplete="off"
                                icon={<KeyRound className="size-3.5" />}
                                onChange={(event) => {
                                    setPassword(event.target.value);
                                }}
                            />
                        </Field>
                    </FieldGrid>
                )}
            </PanelDialog>
        </AppShell>
    );
}
