import { useCallback, useEffect, useState, type JSX } from 'react';
import { LockIcon, ShieldCheckIcon } from 'lucide-react';
import { toast } from 'sonner';

import { startRegistration } from '@simplewebauthn/browser';

import { disableTotp } from '../../api/accountApi';

import {
    completePasskeyRegistration,
    confirmTotpEnrollment,
    enrollTotp,
    fetchMfaStatus,
    listSessions,
    regenerateRecoveryCodes,
    requestPasskeyRegistrationOptions,
    revokeAllSessions,
    revokeSession,
} from '../../api/authApi';
import AppShell from '../../components/AppShell';
import {
    DataTable,
    Field,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    Pill,
    QrCode,
    Row,
    RowList,
    Stat,
    StatGrid,
    Status,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { ApiError } from '../../lib/http';
import type { MfaStatus, SessionSummary } from '../../types/auth';

/**
 * Second factors and active sessions.
 *
 * The two questions this screen answers are "what can sign in as me?" and "what *is* signed in as
 * me right now?" — so passkeys, TOTP and recovery codes sit above a list of live sessions with a
 * way to end any of them. Revoking is the one destructive action here and is always explicit;
 * changing a password deliberately does not revoke anything (see the profile screen).
 */
export default function SettingsSecurity(): JSX.Element {
    const { t } = useTranslation();

    const [mfa, setMfa] = useState<MfaStatus | null>(null);
    const [sessions, setSessions] = useState<SessionSummary[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [totp, setTotp] = useState<{ secret: string; provisioningUri: string } | null>(null);
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [disabling, setDisabling] = useState(false);
    const [password, setPassword] = useState('');
    const errors = useFormErrors();
    const [reloadToken, setReloadToken] = useState(0);

    const describeFailure = useCallback(
        (cause: unknown): string =>
            cause instanceof ApiError && cause.reason === 'network'
                ? t((d) => d.errors.network)
                : t((d) => d.errors.unexpected),
        [t],
    );

    useEffect(() => {
        let active = true;

        void fetchMfaStatus().then(
            (loaded) => {
                if (active) {
                    setMfa(loaded);
                }
            },
            () => {
                if (active) {
                    setMfa(null);
                }
            },
        );

        listSessions()
            .then((loaded) => {
                if (active) {
                    setSessions(loaded);
                }
            })
            .catch((cause: unknown) => {
                if (active) {
                    setSessions([]);
                    toast.error(
                        t((d) => d.pages.settingsSecurity.loadFailed),
                        {
                            description: describeFailure(cause),
                        },
                    );
                }
            });

        return () => {
            active = false;
        };
    }, [reloadToken, describeFailure, t]);

    const reload = (): void => {
        setSessions(null);
        setReloadToken((token) => token + 1);
    };

    const endSession = async (session: SessionSummary): Promise<void> => {
        setBusy(true);

        try {
            await revokeSession(session.id);
            toast.success(t((d) => d.pages.settingsSecurity.sessionRevoked));
            reload();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.revokeFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const endOthers = async (): Promise<void> => {
        setBusy(true);

        try {
            const count = await revokeAllSessions(true);
            toast.success(t((d) => d.pages.settingsSecurity.othersRevoked).replace('{count}', String(count)));
            reload();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.revokeFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const enrolTotp = async (): Promise<void> => {
        setBusy(true);

        try {
            setTotp(await enrollTotp());
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.totpFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const confirmTotp = async (): Promise<void> => {
        setBusy(true);

        try {
            // Confirming issues a fresh set of recovery codes, shown once. They are the only way
            // back in if the authenticator is lost, so the dialog stays open until dismissed.
            setRecoveryCodes(await confirmTotpEnrollment(code));
            setTotp(null);
            setCode('');
            toast.success(t((d) => d.pages.settingsSecurity.totpEnabled));
            reload();
        } catch (cause: unknown) {
            // A rejected code or a wrong password is named by the server; the field shows it.
            if (errors.capture(cause)) {
                return;
            }

            toast.error(
                t((d) => d.pages.settingsSecurity.totpCodeRejected),
                {
                    description: describeFailure(cause),
                },
            );
        } finally {
            setBusy(false);
        }
    };

    const addPasskey = async (): Promise<void> => {
        setBusy(true);

        try {
            const optionsJson = (await requestPasskeyRegistrationOptions()) as unknown as Parameters<
                typeof startRegistration
            >[0]['optionsJSON'];
            const credential = await startRegistration({ optionsJSON: optionsJson });

            await completePasskeyRegistration(credential as unknown as Record<string, unknown>, null);
            toast.success(t((d) => d.pages.settingsSecurity.passkeyAdded));
            reload();
        } catch (cause: unknown) {
            // A user who dismisses the browser's own prompt lands here too; that is not an error
            // worth a red toast, but it is indistinguishable from a real failure without parsing
            // DOMException names, so it is reported plainly rather than guessed at.
            toast.error(
                t((d) => d.pages.settingsSecurity.passkeyFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const newRecoveryCodes = async (): Promise<void> => {
        setBusy(true);

        try {
            setRecoveryCodes(await regenerateRecoveryCodes());
            toast.success(t((d) => d.pages.settingsSecurity.codesRegenerated));
            reload();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.codesFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const turnOffTotp = async (): Promise<void> => {
        setBusy(true);

        try {
            await disableTotp(password === '' ? null : password);
            setDisabling(false);
            setPassword('');
            toast.success(t((d) => d.pages.settingsSecurity.totpDisabled));
            reload();
        } catch (cause: unknown) {
            // A rejected code or a wrong password is named by the server; the field shows it.
            if (errors.capture(cause)) {
                return;
            }

            toast.error(
                t((d) => d.pages.settingsSecurity.totpDisableFailed),
                {
                    description: describeFailure(cause),
                },
            );
        } finally {
            setBusy(false);
        }
    };

    const others = (sessions ?? []).filter((session) => !session.isCurrent).length;
    const protectedAccount = mfa !== null && (mfa.totpEnrolled || mfa.passkeysCount > 0);

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.settingsSecurity.title)}
                lede={t((d) => d.pages.settingsSecurity.description)}
            >
                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.settingsSecurity.stats.mfa)}
                        value={
                            mfa === null ? (
                                '—'
                            ) : (
                                <Status tone={protectedAccount ? 'ok' : 'warn'}>
                                    {protectedAccount
                                        ? t((d) => d.pages.settingsSecurity.stats.on)
                                        : t((d) => d.pages.settingsSecurity.stats.off)}
                                </Status>
                            )
                        }
                        sub={t((d) => d.pages.settingsSecurity.stats.mfaSub)}
                    />
                    <Stat
                        label={t((d) => d.pages.settingsSecurity.stats.passkeys)}
                        value={mfa === null ? '—' : mfa.passkeysCount}
                    />
                    <Stat
                        label={t((d) => d.pages.settingsSecurity.stats.recoveryCodes)}
                        value={mfa === null ? '—' : mfa.recoveryCodesRemaining}
                        sub={t((d) => d.pages.settingsSecurity.stats.recoveryCodesSub)}
                    />
                    <Stat
                        label={t((d) => d.pages.settingsSecurity.stats.sessions)}
                        value={sessions === null ? '—' : sessions.length}
                        sub={t((d) => d.pages.settingsSecurity.stats.sessionsSub)}
                    />
                </StatGrid>

                <Panel
                    title={t((d) => d.pages.settingsSecurity.factorsTitle)}
                    description={t((d) => d.pages.settingsSecurity.factorsDescription)}
                >
                    <RowList>
                        <Row
                            title={t((d) => d.pages.settingsSecurity.passkeys)}
                            subtitle={t((d) => d.pages.settingsSecurity.passkeysHint)}
                        >
                            <div className="flex items-center gap-2">
                                {mfa !== null && mfa.passkeysCount > 0 ? (
                                    <Status tone="ok">
                                        {t((d) => d.pages.settingsSecurity.registered).replace(
                                            '{count}',
                                            String(mfa.passkeysCount),
                                        )}
                                    </Status>
                                ) : (
                                    <Pill tone="locked">{t((d) => d.pages.settingsSecurity.notSet)}</Pill>
                                )}
                                <PanelButton
                                    disabled={busy}
                                    onClick={() => {
                                        void addPasskey();
                                    }}
                                >
                                    {t((d) => d.pages.settingsSecurity.addPasskey)}
                                </PanelButton>
                            </div>
                        </Row>
                        <Row
                            title={t((d) => d.pages.settingsSecurity.totp)}
                            subtitle={t((d) => d.pages.settingsSecurity.totpHint)}
                        >
                            <div className="flex items-center gap-2">
                                {mfa?.totpEnrolled === true ? (
                                    <>
                                        <Status tone="ok">{t((d) => d.pages.settingsSecurity.active)}</Status>
                                        <PanelButton
                                            disabled={busy}
                                            onClick={() => {
                                                setDisabling(true);
                                            }}
                                        >
                                            {t((d) => d.pages.settingsSecurity.disable)}
                                        </PanelButton>
                                    </>
                                ) : (
                                    <>
                                        <Pill tone="locked">{t((d) => d.pages.settingsSecurity.notSet)}</Pill>
                                        <PanelButton
                                            disabled={busy}
                                            onClick={() => {
                                                void enrolTotp();
                                            }}
                                        >
                                            {t((d) => d.pages.settingsSecurity.enableTotp)}
                                        </PanelButton>
                                    </>
                                )}
                            </div>
                        </Row>
                        <Row
                            title={t((d) => d.pages.settingsSecurity.recoveryCodes)}
                            subtitle={
                                mfa?.totpEnrolled === true
                                    ? t((d) => d.pages.settingsSecurity.recoveryCodesHint)
                                    : t((d) => d.pages.settingsSecurity.recoveryCodesLocked)
                            }
                        >
                            <div className="flex items-center gap-2">
                                <Pill tone={mfa !== null && mfa.recoveryCodesRemaining > 0 ? 'neutral' : 'locked'}>
                                    {t((d) => d.pages.settingsSecurity.remaining).replace(
                                        '{count}',
                                        String(mfa?.recoveryCodesRemaining ?? 0),
                                    )}
                                </Pill>
                                {/* Recovery codes exist only alongside an authenticator app: the
                                    server issues them when TOTP is confirmed and refuses to
                                    regenerate them otherwise. Offering the button regardless
                                    produced a 422 that read as a bug. */}
                                <PanelButton
                                    disabled={busy || mfa?.totpEnrolled !== true}
                                    onClick={() => {
                                        void newRecoveryCodes();
                                    }}
                                >
                                    {t((d) => d.pages.settingsSecurity.regenerate)}
                                </PanelButton>
                            </div>
                        </Row>
                    </RowList>
                    <p className="note mt-3">{t((d) => d.pages.settingsSecurity.enrolNotice)}</p>
                </Panel>

                <Panel
                    title={t((d) => d.pages.settingsSecurity.sessionsTitle)}
                    description={t((d) => d.pages.settingsSecurity.sessionsDescription)}
                    flush
                    footNote={t((d) => d.pages.settingsSecurity.sessionsNote)}
                    footActions={
                        others > 0 ? (
                            <PanelButton
                                disabled={busy}
                                onClick={() => {
                                    void endOthers();
                                }}
                            >
                                {t((d) => d.pages.settingsSecurity.revokeOthers)}
                            </PanelButton>
                        ) : undefined
                    }
                >
                    {sessions === null ? (
                        <div className="space-y-2 p-3">
                            {[0, 1].map((index) => (
                                <Skeleton key={index} className="tile h-9 w-full" />
                            ))}
                        </div>
                    ) : sessions.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.settingsSecurity.noSessions)}</p>
                    ) : (
                        <DataTable
                            head={
                                <>
                                    <th>{t((d) => d.pages.settingsSecurity.columns.device)}</th>
                                    <th>{t((d) => d.pages.settingsSecurity.columns.ip)}</th>
                                    <th>{t((d) => d.pages.settingsSecurity.columns.lastUsed)}</th>
                                    <th />
                                </>
                            }
                        >
                            {sessions.map((session) => (
                                <tr key={session.id}>
                                    <td>
                                        <div className="identity-name">
                                            {session.userAgent ?? t((d) => d.pages.settingsSecurity.unknownDevice)}
                                        </div>
                                        {session.isCurrent ? (
                                            <Pill tone="accent">{t((d) => d.pages.settingsSecurity.thisDevice)}</Pill>
                                        ) : null}
                                    </td>
                                    <td className="mono">{session.ipAddress ?? '—'}</td>
                                    <td className="whitespace-nowrap">
                                        {new Date(session.lastUsedAt).toLocaleString()}
                                    </td>
                                    <td className="text-right">
                                        {session.isCurrent ? null : (
                                            <PanelButton
                                                disabled={busy}
                                                onClick={() => {
                                                    void endSession(session);
                                                }}
                                            >
                                                {t((d) => d.pages.settingsSecurity.revoke)}
                                            </PanelButton>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            <PanelDialog
                open={totp !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setTotp(null);
                        setCode('');
                    }
                }}
                title={t((d) => d.pages.settingsSecurity.totpDialogTitle)}
                description={t((d) => d.pages.settingsSecurity.totpDialogHint)}
                actions={
                    <>
                        <PanelButton
                            variant="outline"
                            onClick={() => {
                                setTotp(null);
                                setCode('');
                            }}
                        >
                            {t((d) => d.pages.settingsSecurity.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={code === '' || busy}
                            onClick={() => {
                                void confirmTotp();
                            }}
                        >
                            {t((d) => d.pages.settingsSecurity.confirm)}
                        </PanelButton>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="flex items-start gap-4">
                        {totp === null ? null : <QrCode value={totp.provisioningUri} />}

                        <ol className="note space-y-2">
                            <li>
                                <span className="step">1</span>
                                {t((d) => d.pages.settingsSecurity.step1)}
                            </li>
                            <li>
                                <span className="step">2</span>
                                {t((d) => d.pages.settingsSecurity.step2)}
                            </li>
                            <li>
                                <span className="step">3</span>
                                {t((d) => d.pages.settingsSecurity.step3)}
                            </li>
                        </ol>
                    </div>

                    <Field
                        label={t((d) => d.pages.settingsSecurity.secret)}
                        hint={t((d) => d.pages.settingsSecurity.secretHint)}
                    >
                        {/* Shown as selectable text beside the QR: a phone scans the code, a
                            desktop authenticator needs the key pasted, and a screen-reader user
                            cannot use the image at all. */}
                        <div className="ctl ctl-readonly secret-box mono-strong border">{totp?.secret ?? ''}</div>
                    </Field>

                    <Field label={t((d) => d.pages.settingsSecurity.code)} error={errors.get('code')}>
                        <PanelInput
                            className="ctl-code"
                            icon={<ShieldCheckIcon className="size-3.5" />}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={8}
                            value={code}
                            onChange={(event) => {
                                setCode(event.target.value);
                                errors.clear('code');
                            }}
                        />
                    </Field>
                </div>
            </PanelDialog>

            <PanelDialog
                open={disabling}
                onOpenChange={(open) => {
                    if (!open) {
                        setDisabling(false);
                        setPassword('');
                    }
                }}
                title={t((d) => d.pages.settingsSecurity.disableTitle)}
                description={t((d) => d.pages.settingsSecurity.disableHint)}
                actions={
                    <>
                        <PanelButton
                            variant="outline"
                            onClick={() => {
                                setDisabling(false);
                                setPassword('');
                            }}
                        >
                            {t((d) => d.pages.settingsSecurity.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={busy}
                            onClick={() => {
                                void turnOffTotp();
                            }}
                        >
                            {t((d) => d.pages.settingsSecurity.disable)}
                        </PanelButton>
                    </>
                }
            >
                <div className="space-y-3">
                    <p className="note">{t((d) => d.pages.settingsSecurity.disableWarning)}</p>

                    <Field
                        label={t((d) => d.pages.settingsSecurity.currentPassword)}
                        hint={t((d) => d.pages.settingsSecurity.currentPasswordHint)}
                        error={errors.get('currentPassword')}
                    >
                        <PanelInput
                            icon={<LockIcon className="size-3.5" />}
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(event) => {
                                setPassword(event.target.value);
                                errors.clear('currentPassword');
                            }}
                        />
                    </Field>
                </div>
            </PanelDialog>

            <PanelDialog
                open={recoveryCodes !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRecoveryCodes(null);
                    }
                }}
                title={t((d) => d.pages.settingsSecurity.codesTitle)}
                description={t((d) => d.pages.settingsSecurity.codesHint)}
                actions={
                    <PanelButton
                        primary
                        onClick={() => {
                            setRecoveryCodes(null);
                        }}
                    >
                        {t((d) => d.pages.settingsSecurity.codesSaved)}
                    </PanelButton>
                }
            >
                <div className="tile grid grid-cols-2 gap-1.5 border p-3">
                    {(recoveryCodes ?? []).map((recoveryCode) => (
                        <span key={recoveryCode} className="mono mono-strong select-all">
                            {recoveryCode}
                        </span>
                    ))}
                </div>
            </PanelDialog>
        </AppShell>
    );
}
