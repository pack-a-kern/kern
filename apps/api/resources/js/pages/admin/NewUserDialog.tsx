import { useState, type JSX } from 'react';
import { AtSignIcon, Check, CopyIcon, Plus, X } from 'lucide-react';
import { toast } from 'sonner';

import { createUser } from '../../api/adminApi';
import ErrorText from '../../components/ErrorText';
import { Field, PanelButton, PanelDialog, PanelInput, PanelSelect } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { describeApiFailure } from '../../lib/apiErrors';

/**
 * Opening an account for somebody else.
 *
 * ## Two steps, because the password exists only once
 *
 * The server generates it — an administrator who picks one picks the same one for everybody — and
 * returns it in the response that creates the account. Only its hash is kept, so there is no screen
 * that can show it again and no query that can recover it. The second step exists to make that
 * plain: the password is displayed, copyable, with a line saying it will not be shown twice.
 *
 * A generated credential also means the administrator never learns a password the person might be
 * reusing elsewhere.
 */
export default function NewUserDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roles: string[];
    onCreated: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const errors = useFormErrors();

    const [email, setEmail] = useState('');
    const [role, setRole] = useState('');
    const [saving, setSaving] = useState(false);
    const [created, setCreated] = useState<{ email: string; password: string } | null>(null);
    // A refusal that belongs to the request rather than to a field — "you may not do this", "that
    // address is taken". Putting those under the email box says the address is malformed, which is
    // a different and wrong thing to tell someone.
    const [problem, setProblem] = useState<string | null>(null);

    const close = (): void => {
        setEmail('');
        setRole('');
        setCreated(null);
        setProblem(null);
        errors.reset();
        props.onOpenChange(false);
    };

    const submit = async (): Promise<void> => {
        setSaving(true);

        setProblem(null);

        try {
            const result = await createUser(email, role === '' ? [] : [role]);

            setCreated({ email, password: result.password });
            props.onCreated();
        } catch (cause: unknown) {
            if (errors.capture(cause)) {
                return;
            }

            setProblem(describeApiFailure(cause, t));
        } finally {
            setSaving(false);
        }
    };

    const copy = async (): Promise<void> => {
        if (created === null) {
            return;
        }

        try {
            await navigator.clipboard.writeText(created.password);
            toast.success(t((d) => d.pages.adminUsers.passwordCopied));
        } catch {
            // A browser that refuses clipboard access is not an error worth a red toast — the
            // password is on screen and can be selected by hand.
        }
    };

    return (
        <PanelDialog
            open={props.open}
            onOpenChange={(next) => {
                if (!next) {
                    close();
                }
            }}
            title={created === null ? t((d) => d.pages.adminUsers.newTitle) : t((d) => d.pages.adminUsers.newDoneTitle)}
            description={
                created === null ? t((d) => d.pages.adminUsers.newHint) : t((d) => d.pages.adminUsers.newDoneHint)
            }
            actions={
                created === null ? (
                    <>
                        <PanelButton variant="outline" onClick={close}>
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.common.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={email === '' || saving}
                            onClick={() => {
                                void submit();
                            }}
                        >
                            <Plus className="size-3.5" aria-hidden="true" />
                            {saving ? t((d) => d.pages.adminUsers.saving) : t((d) => d.pages.adminUsers.newSubmit)}
                        </PanelButton>
                    </>
                ) : (
                    <PanelButton primary onClick={close}>
                        <Check className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.adminUsers.newDone)}
                    </PanelButton>
                )
            }
        >
            {created === null ? (
                <div className="space-y-3">
                    <ErrorText message={problem} />

                    <Field label={t((d) => d.pages.adminUsers.columns.user)} error={errors.get('email')}>
                        <PanelInput
                            icon={<AtSignIcon className="size-3.5" />}
                            type="email"
                            autoComplete="off"
                            autoFocus
                            value={email}
                            onChange={(event) => {
                                setEmail(event.target.value);
                                errors.clear('email');
                            }}
                        />
                    </Field>

                    <Field label={t((d) => d.pages.adminUsers.columns.role)}>
                        <PanelSelect
                            value={role}
                            onValueChange={setRole}
                            placeholder={t((d) => d.pages.adminUsers.noRole)}
                            emptyLabel={t((d) => d.pages.adminUsers.noRole)}
                            options={props.roles.map((value) => ({
                                value,
                                label: t((d) => d.pages.adminUsers.roles[value] ?? value),
                            }))}
                        />
                    </Field>
                </div>
            ) : (
                <div className="space-y-3">
                    <Field label={t((d) => d.pages.adminUsers.columns.user)}>
                        <PanelInput value={created.email} readOnly disabled onChange={() => undefined} />
                    </Field>

                    <Field
                        label={t((d) => d.pages.adminUsers.newPassword)}
                        hint={t((d) => d.pages.adminUsers.newPasswordHint)}
                    >
                        <PanelInput
                            className="mono-strong"
                            value={created.password}
                            readOnly
                            onChange={() => undefined}
                            actionEnd={
                                <PanelButton
                                    inline
                                    onClick={() => {
                                        void copy();
                                    }}
                                >
                                    <CopyIcon className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.adminUsers.copy)}
                                </PanelButton>
                            }
                        />
                    </Field>
                </div>
            )}
        </PanelDialog>
    );
}
