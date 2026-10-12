import { useState, type JSX } from 'react';
import { Building2Icon, Check, MapPinIcon, TagIcon, UserIcon, X } from 'lucide-react';
import { toast } from 'sonner';

import { updateSubject } from '../../api/accountApi';
import { Field, FieldGrid, FieldSpan, PanelButton, PanelDialog, PanelInput, PanelSelect } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useCounties } from './useCounties';
import type { TaxSubject } from '../../types/account';
import AvatarField from '../../components/AvatarField';
import { removeSubjectAvatar, subjectAvatarUrl, uploadSubjectAvatar } from '../../api/accountApi';
import { initialsFor } from '../../lib/initials';

/**
 * Correcting a taxpayer.
 *
 * ## Why it opens nearly empty
 *
 * Every field here is encrypted under this taxpayer's own key, and the list endpoint returns only
 * what a table needs — the display name. Prefilling the rest would mean decrypting a person's name,
 * address and locality to render a form they may close without touching, on a screen that lists
 * every taxpayer they can see. So the form starts blank and sends only what was filled in: an
 * untouched field is left exactly as it was, rather than being blanked by an empty input.
 *
 * The CNP and the date of birth are absent entirely — see `UpdatePfSubject` for why identity is not
 * a correctable detail.
 */
export default function EditSubjectDialog(props: {
    subject: TaxSubject;
    onClose: () => void;
    onSaved: () => void;
    /** Told the new version, or null once the picture is gone, so the list behind can redraw. */
    onAvatarChange: (subjectId: string, version: string | null) => void;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const counties = useCounties();
    const [form, setForm] = useState({
        displayName: props.subject.displayName,
        firstName: '',
        lastName: '',
        countyCode: '',
        locality: '',
        address: '',
    });
    const [saving, setSaving] = useState(false);
    const errors = useFormErrors();

    const set =
        (key: keyof typeof form) =>
        (value: string): void => {
            setForm((current) => ({ ...current, [key]: value }));
            errors.clear(key);
        };

    const submit = async (): Promise<void> => {
        setSaving(true);

        try {
            // Only what was filled in. `undefined` means "not sent", which the server reads as
            // "leave it alone" — an empty string would be a deliberate blanking.
            await updateSubject(props.subject.id, {
                ...(form.displayName === '' ? {} : { displayName: form.displayName }),
                ...(form.firstName === '' ? {} : { firstName: form.firstName }),
                ...(form.lastName === '' ? {} : { lastName: form.lastName }),
                ...(form.countyCode === '' ? {} : { countyCode: form.countyCode }),
                ...(form.locality === '' ? {} : { locality: form.locality }),
                ...(form.address === '' ? {} : { address: form.address }),
            });
            errors.reset();
            toast.success(t((d) => d.pages.settingsSubjects.saved));
            props.onSaved();
        } catch (cause: unknown) {
            if (errors.capture(cause)) {
                toast.error(t((d) => d.pages.settingsSubjects.checkFields));

                return;
            }

            toast.error(
                t((d) => d.pages.settingsSubjects.saveFailed),
                { description: props.describeFailure(cause) },
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <PanelDialog
            open
            onOpenChange={(next) => {
                if (!next) {
                    props.onClose();
                }
            }}
            title={t((d) => d.pages.settingsSubjects.editTitle)}
            description={t((d) => d.pages.settingsSubjects.editHint)}
            actions={
                <>
                    <PanelButton variant="outline" onClick={props.onClose}>
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.settingsSubjects.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={saving}
                        onClick={() => {
                            void submit();
                        }}
                    >
                        <Check className="size-3.5" aria-hidden="true" />
                        {saving ? t((d) => d.pages.settingsSubjects.saving) : t((d) => d.pages.settingsSubjects.save)}
                    </PanelButton>
                </>
            }
        >
            {/* Above the fields and uploaded on its own, like a user's: a refusal from the scanner
                belongs to the picture, not to a Save the person thought was about a name. Only the
                owner may change it, and the server says so too — this is the screen being honest
                rather than the screen deciding. */}
            {props.subject.role !== 'OWNER' ? null : (
                <AvatarField
                    initials={initialsFor(props.subject.displayName)}
                    src={
                        props.subject.avatarUpdatedAt === null
                            ? undefined
                            : subjectAvatarUrl(props.subject.id, props.subject.avatarUpdatedAt)
                    }
                    onUpload={(file) => uploadSubjectAvatar(props.subject.id, file)}
                    onRemove={() => removeSubjectAvatar(props.subject.id)}
                    onChange={(version) => {
                        props.onAvatarChange(props.subject.id, version);
                    }}
                />
            )}

            <FieldGrid>
                <FieldSpan>
                    <Field
                        label={t((d) => d.pages.settingsSubjects.fields.displayName)}
                        hint={t((d) => d.pages.settingsSubjects.fields.displayNameHint)}
                        error={errors.get('displayName')}
                    >
                        <PanelInput
                            icon={<TagIcon className="size-3.5" />}
                            value={form.displayName}
                            onChange={(event) => {
                                set('displayName')(event.target.value);
                            }}
                        />
                    </Field>
                </FieldSpan>

                <Field label={t((d) => d.pages.settingsSubjects.fields.firstName)} error={errors.get('firstName')}>
                    <PanelInput
                        icon={<UserIcon className="size-3.5" />}
                        placeholder={t((d) => d.pages.settingsSubjects.unchanged)}
                        value={form.firstName}
                        onChange={(event) => {
                            set('firstName')(event.target.value);
                        }}
                    />
                </Field>

                <Field label={t((d) => d.pages.settingsSubjects.fields.lastName)} error={errors.get('lastName')}>
                    <PanelInput
                        icon={<UserIcon className="size-3.5" />}
                        placeholder={t((d) => d.pages.settingsSubjects.unchanged)}
                        value={form.lastName}
                        onChange={(event) => {
                            set('lastName')(event.target.value);
                        }}
                    />
                </Field>

                <Field label={t((d) => d.pages.settingsSubjects.fields.countyCode)} error={errors.get('countyCode')}>
                    <PanelSelect
                        searchable
                        value={form.countyCode}
                        placeholder={t((d) => d.pages.settingsSubjects.unchanged)}
                        onValueChange={set('countyCode')}
                        options={counties.map((county) => ({
                            value: county.code,
                            label: `${county.code} · ${county.name}`,
                        }))}
                    />
                </Field>

                <Field label={t((d) => d.pages.settingsSubjects.fields.locality)} error={errors.get('locality')}>
                    <PanelInput
                        icon={<Building2Icon className="size-3.5" />}
                        placeholder={t((d) => d.pages.settingsSubjects.unchanged)}
                        value={form.locality}
                        onChange={(event) => {
                            set('locality')(event.target.value);
                        }}
                    />
                </Field>

                <Field label={t((d) => d.pages.settingsSubjects.fields.address)} error={errors.get('address')}>
                    <PanelInput
                        icon={<MapPinIcon className="size-3.5" />}
                        placeholder={t((d) => d.pages.settingsSubjects.unchanged)}
                        value={form.address}
                        onChange={(event) => {
                            set('address')(event.target.value);
                        }}
                    />
                </Field>
            </FieldGrid>

            <p className="note mt-3">{t((d) => d.pages.settingsSubjects.privacyNote)}</p>
        </PanelDialog>
    );
}
