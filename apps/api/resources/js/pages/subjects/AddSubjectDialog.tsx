import { useState, type JSX } from 'react';
import {
    Building2Icon,
    CalendarIcon,
    GlobeIcon,
    IdCardIcon,
    MapPinIcon,
    Plus,
    TagIcon,
    UserIcon,
    X,
} from 'lucide-react';
import { toast } from 'sonner';

import { type RegisterPfInput, inspectCnp, registerPfSubject } from '../../api/accountApi';
import { Field, FieldGrid, FieldSpan, PanelButton, PanelDialog, PanelInput, PanelSelect } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useCounties } from './useCounties';

/**
 * The country every taxpayer here is registered under.
 *
 * The server decides it (`config/subjects.php`); this is the same value stated on the form so a
 * person can see what will be stored. If the two ever disagree the server wins, which is why this
 * is not sent.
 */
const COUNTRY = 'RO';

export default function AddSubjectDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAdded: () => void;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const counties = useCounties();
    const [form, setForm] = useState<RegisterPfInput>({
        displayName: '',
        firstName: '',
        lastName: '',
        cnp: '',
        birthDate: '',
        countyCode: '',
        locality: '',
        address: '',
    });
    const [saving, setSaving] = useState(false);
    const errors = useFormErrors();

    /*
     * The form is in two steps, because the CNP answers two of its own questions.
     *
     * A CNP carries the birth date and the county of registration inside it. Asking for those
     * separately invites a form whose fields contradict the identifier that produced them — so the
     * first step asks for the number alone, the server reads it, and the rest of the form opens
     * with what it said already filled in.
     *
     * The check happens there rather than here on purpose: the same arithmetic in TypeScript would
     * answer without a round trip, but it would be a second copy of the rule that decides what this
     * application accepts — including the refusal of non-resident CNPs, which is a decision about
     * the product rather than a calculation.
     */
    const [checking, setChecking] = useState(false);
    const [details, setDetails] = useState<{ birthDate: string; countyCode: string } | null>(null);

    const checkCnp = async (): Promise<void> => {
        setChecking(true);
        errors.reset();

        try {
            const read = await inspectCnp(form.cnp);

            setDetails({ birthDate: read.birthDate, countyCode: read.countyCode });
            setForm((current) => ({
                ...current,
                birthDate: read.birthDate,
                countyCode: read.countyCode,
                // Only a Bucharest sector arrives here; everywhere else the CNP names a county and
                // the locality is still a question.
                locality: read.locality ?? current.locality,
            }));
        } catch (cause: unknown) {
            // The server names what is wrong with the number — a check digit, a date that does not
            // exist, a series this application does not serve. Shown on the field, not in a toast.
            errors.set('cnp', props.describeFailure(cause));
        } finally {
            setChecking(false);
        }
    };

    // Whether the display name is still being derived from the person's name.
    //
    // "Ion Popescu" is what someone would type anyway, so typing it twice is a chore the form can
    // do for them. It stops the moment they edit the name themselves — an account holding three
    // taxpayers with the same legal name needs "Mama", "Firma", "Eu", and a field that kept
    // overwriting that would be worse than one that never helped.
    const [derivedName, setDerivedName] = useState(true);

    const set = (key: keyof RegisterPfInput) => (value: string) => {
        setForm((current) => {
            const next = { ...current, [key]: value };

            if (derivedName && (key === 'firstName' || key === 'lastName')) {
                next.displayName = `${next.firstName} ${next.lastName}`.trim();
            }

            return next;
        });
        errors.clear(key);

        if (key === 'displayName') {
            setDerivedName(value === '');
        }
    };

    const ready = details !== null && form.displayName !== '' && form.firstName !== '' && form.lastName !== '';

    const submit = async (): Promise<void> => {
        setSaving(true);

        try {
            await registerPfSubject(form);
            errors.reset();
            toast.success(t((d) => d.pages.settingsSubjects.added));
            props.onAdded();
        } catch (cause: unknown) {
            // The server names the fields it rejected; show it on them. The toast then only has to
            // say that something needs attention, because the detail is where it can be acted on.
            if (errors.capture(cause)) {
                toast.error(t((d) => d.pages.settingsSubjects.checkFields));

                return;
            }

            toast.error(
                t((d) => d.pages.settingsSubjects.addFailed),
                { description: props.describeFailure(cause) },
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <PanelDialog
            open={props.open}
            onOpenChange={props.onOpenChange}
            size="lg"
            title={t((d) => d.pages.settingsSubjects.addTitle)}
            description={t((d) => d.pages.settingsSubjects.addHint)}
            actions={
                <>
                    <PanelButton
                        variant="outline"
                        onClick={() => {
                            props.onOpenChange(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.settingsSubjects.cancel)}
                    </PanelButton>
                    {details === null ? null : (
                        <PanelButton
                            primary
                            disabled={!ready || saving}
                            onClick={() => {
                                void submit();
                            }}
                        >
                            <Plus className="size-3.5" aria-hidden="true" />
                            {saving
                                ? t((d) => d.pages.settingsSubjects.saving)
                                : t((d) => d.pages.settingsSubjects.add)}
                        </PanelButton>
                    )}
                </>
            }
        >
            {details === null ? (
                <div className="space-y-3">
                    <Field
                        label={t((d) => d.pages.settingsSubjects.fields.cnp)}
                        hint={t((d) => d.pages.settingsSubjects.fields.cnpHint)}
                        error={errors.get('cnp')}
                    >
                        <PanelInput
                            icon={<IdCardIcon className="size-3.5" />}
                            inputMode="numeric"
                            autoComplete="off"
                            autoFocus
                            value={form.cnp}
                            onChange={(event) => {
                                set('cnp')(event.target.value);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && form.cnp !== '') {
                                    event.preventDefault();
                                    void checkCnp();
                                }
                            }}
                        />
                    </Field>

                    <PanelButton
                        primary
                        inline
                        disabled={form.cnp === '' || checking}
                        onClick={() => {
                            void checkCnp();
                        }}
                    >
                        {checking
                            ? t((d) => d.pages.settingsSubjects.checkingCnp)
                            : t((d) => d.pages.settingsSubjects.continueLabel)}
                    </PanelButton>
                </div>
            ) : (
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
                            autoComplete="off"
                            value={form.firstName}
                            onChange={(event) => {
                                set('firstName')(event.target.value);
                            }}
                        />
                    </Field>

                    <Field label={t((d) => d.pages.settingsSubjects.fields.lastName)} error={errors.get('lastName')}>
                        <PanelInput
                            icon={<UserIcon className="size-3.5" />}
                            autoComplete="off"
                            value={form.lastName}
                            onChange={(event) => {
                                set('lastName')(event.target.value);
                            }}
                        />
                    </Field>

                    {/* Both settled by the number itself: the CNP was accepted, and the birth date
                    came out of it. Shown so a person can see what will be stored — and so a typo
                    announces itself as a wrong birth date — but not editable, because the server
                    derives them from the CNP whatever the form sends. */}
                    <Field label={t((d) => d.pages.settingsSubjects.fields.cnp)}>
                        <PanelInput
                            icon={<IdCardIcon className="size-3.5" />}
                            value={form.cnp}
                            readOnly
                            disabled
                            onChange={() => {
                                // Settled in the first step.
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.settingsSubjects.fields.birthDate)}
                        hint={t((d) => d.pages.settingsSubjects.fields.birthDateHint)}
                    >
                        <PanelInput
                            icon={<CalendarIcon className="size-3.5" />}
                            value={form.birthDate}
                            readOnly
                            disabled
                            onChange={() => {
                                // Derived from the CNP.
                            }}
                        />
                    </Field>

                    {/* Shown, not asked: every taxpayer this application registers is Romanian, and a
                    field the server will overwrite is more honest as a disabled statement of what
                    will be stored than as an input that pretends to be a choice. It is not part of
                    the payload — the server reads it from `config/subjects.php`. */}
                    <Field label={t((d) => d.pages.settingsSubjects.fields.country)}>
                        <PanelInput
                            icon={<GlobeIcon className="size-3.5" />}
                            value={COUNTRY}
                            readOnly
                            disabled
                            onChange={() => {
                                // A disabled field emits nothing; this exists only to satisfy React's
                                // controlled-input contract.
                            }}
                        />
                    </Field>

                    {/* A county, not a country — the two were easy to confuse while this was a free
                    text box next to a disabled `RO`. Picking from the 42 also means what is stored
                    is a code ANAF recognises, rather than whatever someone typed. */}
                    <Field
                        label={t((d) => d.pages.settingsSubjects.fields.countyCode)}
                        error={errors.get('countyCode')}
                    >
                        <PanelSelect
                            searchable
                            value={form.countyCode}
                            placeholder={t((d) => d.pages.settingsSubjects.fields.countyPlaceholder)}
                            emptyLabel={t((d) => d.pages.settingsSubjects.fields.countyNone)}
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
                            autoComplete="off"
                            value={form.locality}
                            onChange={(event) => {
                                set('locality')(event.target.value);
                            }}
                        />
                    </Field>

                    <Field label={t((d) => d.pages.settingsSubjects.fields.address)} error={errors.get('address')}>
                        <PanelInput
                            icon={<MapPinIcon className="size-3.5" />}
                            autoComplete="off"
                            value={form.address}
                            onChange={(event) => {
                                set('address')(event.target.value);
                            }}
                        />
                    </Field>
                </FieldGrid>
            )}

            <p className="note mt-3">{t((d) => d.pages.settingsSubjects.privacyNote)}</p>
        </PanelDialog>
    );
}
