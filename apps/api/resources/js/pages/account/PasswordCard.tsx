import { useState, type JSX } from 'react';
import { KeyRoundIcon, LockIcon } from 'lucide-react';
import { toast } from 'sonner';

import { changePassword } from '../../api/accountApi';
import { Field, FieldGrid, FieldSpan, Panel, PanelButton, PanelInput } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';

/** Changing the password. The current one is required even though the session is authenticated. */
export default function PasswordCard(props: {
    hasPassword: boolean;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
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
            icon={<LockIcon className="size-4" />}
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
