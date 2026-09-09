import { useCallback, useEffect, useState, type JSX } from 'react';
import { KeyRoundIcon, LockIcon } from 'lucide-react';
import { toast } from 'sonner';

import { changePassword, getProfile } from '../../api/accountApi';
import AppShell from '../../components/AppShell';
import {
    Detail,
    DetailList,
    Field,
    FieldGrid,
    FieldSpan,
    Page,
    Panel,
    PanelButton,
    PanelInput,
    Pill,
    Status,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { ApiError } from '../../lib/http';
import type { Profile } from '../../types/account';

/**
 * The signed-in account.
 *
 * Thin on purpose. Names, CNP and addresses are properties of a *taxpayer*, encrypted with that
 * tax subject's own key, so they live on the Tax subjects screen. What is here is what `users`
 * actually holds: an email address, a password, a status — plus the platform roles the account
 * carries, which people otherwise have no way to see about themselves.
 */
export default function SettingsProfile(): JSX.Element {
    const { t } = useTranslation();

    const [profile, setProfile] = useState<Profile | null>(null);
    const [failed, setFailed] = useState(false);
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
            }

            return t((d) => d.errors.unexpected);
        },
        [t],
    );

    useEffect(() => {
        let active = true;

        getProfile()
            .then((loaded) => {
                if (active) {
                    setProfile(loaded);
                    setFailed(false);
                }
            })
            .catch((cause: unknown) => {
                if (active) {
                    setFailed(true);
                    toast.error(
                        t((d) => d.pages.settingsProfile.loadFailed),
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

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.settingsProfile.title)}
                lede={t((d) => d.pages.settingsProfile.description)}
                actions={
                    failed ? (
                        <PanelButton
                            onClick={() => {
                                setProfile(null);
                                setFailed(false);
                                setReloadToken((token) => token + 1);
                            }}
                        >
                            {t((d) => d.pages.settingsProfile.retry)}
                        </PanelButton>
                    ) : undefined
                }
            >
                <div className="columns-1 gap-3.5 xl:columns-2">
                    <Panel
                        title={t((d) => d.pages.settingsProfile.accountTitle)}
                        description={t((d) => d.pages.settingsProfile.accountDescription)}
                    >
                        {profile === null ? (
                            <Skeleton className="tile h-28 w-full" />
                        ) : (
                            <DetailList>
                                <Detail label={t((d) => d.pages.settingsProfile.email)}>
                                    <span className="font-semibold">{profile.email}</span>
                                    {profile.emailVerifiedAt === null ? (
                                        <div>
                                            <Pill tone="locked">
                                                {t((d) => d.pages.settingsProfile.emailUnverified)}
                                            </Pill>
                                        </div>
                                    ) : null}
                                </Detail>
                                <Detail label={t((d) => d.pages.settingsProfile.status)}>
                                    <Status tone={profile.status === 'ACTIVE' ? 'ok' : 'warn'}>
                                        {profile.status ?? '—'}
                                    </Status>
                                </Detail>
                                <Detail label={t((d) => d.pages.settingsProfile.roles)}>
                                    {profile.roles.length === 0 ? (
                                        <span className="note">{t((d) => d.pages.settingsProfile.noRoles)}</span>
                                    ) : (
                                        <div className="flex flex-wrap gap-1">
                                            {profile.roles.map((role) => (
                                                <Pill key={role} tone="accent">
                                                    {role}
                                                </Pill>
                                            ))}
                                        </div>
                                    )}
                                </Detail>
                                <Detail label={t((d) => d.pages.settingsProfile.created)}>
                                    {profile.createdAt === null
                                        ? '—'
                                        : new Date(profile.createdAt).toLocaleDateString()}
                                </Detail>
                            </DetailList>
                        )}
                        <p className="note mt-3">{t((d) => d.pages.settingsProfile.identityNotice)}</p>
                    </Panel>

                    <PasswordCard hasPassword={profile?.hasPassword ?? false} describeFailure={describeFailure} />
                </div>
            </Page>
        </AppShell>
    );
}

/** Changing the password. The current one is required even though the session is authenticated. */
function PasswordCard(props: { hasPassword: boolean; describeFailure: (cause: unknown) => string }): JSX.Element {
    const { t } = useTranslation();
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [saving, setSaving] = useState(false);
    const errors = useFormErrors();

    const submit = async (): Promise<void> => {
        setSaving(true);

        try {
            await changePassword({ currentPassword: current, password: next, passwordConfirmation: confirmation });
            errors.reset();
            setCurrent('');
            setNext('');
            setConfirmation('');
            toast.success(t((d) => d.pages.settingsProfile.passwordChanged));
        } catch (cause: unknown) {
            // A wrong current password, or one that fails the strength rules, is named by the
            // server — shown on the field rather than in a toast the person cannot act on.
            if (errors.capture(cause)) {
                return;
            }

            toast.error(
                t((d) => d.pages.settingsProfile.passwordFailed),
                {
                    description: props.describeFailure(cause),
                },
            );
        } finally {
            setSaving(false);
        }
    };

    const ready = current !== '' && next !== '' && confirmation !== '';

    return (
        <Panel
            title={t((d) => d.pages.settingsProfile.passwordTitle)}
            description={t((d) => d.pages.settingsProfile.passwordDescription)}
            footNote={t((d) => d.pages.settingsProfile.passwordNote)}
            footActions={
                <PanelButton
                    primary
                    disabled={!ready || saving}
                    onClick={() => {
                        void submit();
                    }}
                >
                    {saving
                        ? t((d) => d.pages.settingsProfile.saving)
                        : t((d) => d.pages.settingsProfile.changePassword)}
                </PanelButton>
            }
        >
            {props.hasPassword ? (
                <FieldGrid>
                    <FieldSpan>
                        <Field
                            label={t((d) => d.pages.settingsProfile.currentPassword)}
                            error={errors.get('currentPassword')}
                        >
                            <PanelInput
                                icon={<LockIcon className="size-3.5" />}
                                type="password"
                                autoComplete="current-password"
                                value={current}
                                onChange={(event) => {
                                    setCurrent(event.target.value);
                                    errors.clear('currentPassword');
                                }}
                            />
                        </Field>
                    </FieldSpan>

                    <Field
                        label={t((d) => d.pages.settingsProfile.newPassword)}
                        hint={t((d) => d.pages.settingsProfile.passwordRules)}
                        error={errors.get('password')}
                    >
                        <PanelInput
                            icon={<KeyRoundIcon className="size-3.5" />}
                            type="password"
                            autoComplete="new-password"
                            value={next}
                            onChange={(event) => {
                                setNext(event.target.value);
                                errors.clear('password');
                            }}
                        />
                    </Field>

                    <Field label={t((d) => d.pages.settingsProfile.confirmPassword)}>
                        <PanelInput
                            icon={<KeyRoundIcon className="size-3.5" />}
                            type="password"
                            autoComplete="new-password"
                            value={confirmation}
                            onChange={(event) => {
                                setConfirmation(event.target.value);
                            }}
                        />
                    </Field>
                </FieldGrid>
            ) : (
                <p className="note">{t((d) => d.pages.settingsProfile.passkeyOnly)}</p>
            )}
        </Panel>
    );
}
