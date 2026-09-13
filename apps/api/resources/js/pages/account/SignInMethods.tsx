import { useState, type JSX } from 'react';
import { LockIcon, ShieldCheckIcon } from 'lucide-react';
import { toast } from 'sonner';
import { startRegistration } from '@simplewebauthn/browser';

import { disableTotp } from '../../api/accountApi';
import {
    completePasskeyRegistration,
    confirmTotpEnrollment,
    enrollTotp,
    regenerateRecoveryCodes,
    requestPasskeyRegistrationOptions,
} from '../../api/authApi';
import {
    Field,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    Pill,
    QrCode,
    Row,
    RowList,
    Status,
} from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import type { MfaStatus } from '../../types/auth';

/**
 * What can sign in as this account: passkeys, an authenticator app, and the recovery codes that
 * stand in for it.
 *
 * The three enrolment dialogs live here rather than on the page, because they are this card's
 * conversation — a page that owns them ends up holding state for a control it does not draw.
 */
export default function SignInMethods(props: {
    mfa: MfaStatus | null;
    onChanged: () => void;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const { mfa } = props;

    const [busy, setBusy] = useState(false);
    const [totp, setTotp] = useState<{ secret: string; provisioningUri: string } | null>(null);
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [disabling, setDisabling] = useState(false);
    const [password, setPassword] = useState('');
    const errors = useFormErrors();

    const enrolTotp = async (): Promise<void> => {
        setBusy(true);

        try {
            setTotp(await enrollTotp());
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.totpFailed),
                { description: props.describeFailure(cause) },
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
            props.onChanged();
        } catch (cause: unknown) {
            // A rejected code or a wrong password is named by the server; the field shows it.
            if (errors.capture(cause)) {
                return;
            }

            toast.error(
                t((d) => d.pages.settingsSecurity.totpCodeRejected),
                { description: props.describeFailure(cause) },
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
            props.onChanged();
        } catch (cause: unknown) {
            // A user who dismisses the browser's own prompt lands here too; that is not an error
            // worth a red toast, but it is indistinguishable from a real failure without parsing
            // DOMException names, so it is reported plainly rather than guessed at.
            toast.error(
                t((d) => d.pages.settingsSecurity.passkeyFailed),
                { description: props.describeFailure(cause) },
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
            props.onChanged();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.codesFailed),
                { description: props.describeFailure(cause) },
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
            props.onChanged();
        } catch (cause: unknown) {
            if (errors.capture(cause)) {
                return;
            }

            toast.error(
                t((d) => d.pages.settingsSecurity.totpDisableFailed),
                { description: props.describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Panel
                title={t((d) => d.pages.settingsSecurity.factorsTitle)}
                description={t((d) => d.pages.settingsSecurity.factorsDescription)}
                icon={<ShieldCheckIcon className="size-4" />}
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
        </>
    );
}
