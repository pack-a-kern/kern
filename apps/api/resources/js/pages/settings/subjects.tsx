import { useCallback, useEffect, useState, type JSX } from 'react';
import { Building2Icon, CalendarIcon, IdCardIcon, MapIcon, MapPinIcon, TagIcon, UserIcon } from 'lucide-react';
import { toast } from 'sonner';

import { type RegisterPfInput, listSubjects, registerPfSubject } from '../../api/accountApi';
import AppShell from '../../components/AppShell';
import {
    DataTable,
    Field,
    FieldGrid,
    FieldSpan,
    Identity,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    Pill,
    Stat,
    StatGrid,
    Status,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { ApiError } from '../../lib/http';
import type { TaxSubject } from '../../types/account';

/** Initials for the avatar disc, from a display name rather than an email. */
function initialsFor(name: string): string {
    const parts = name.split(/\s+/).filter((part) => part !== '');

    return (parts.length > 1 ? `${parts[0]?.[0] ?? ''}${parts[1]?.[0] ?? ''}` : name.slice(0, 2)).toUpperCase();
}

/**
 * The taxpayers this account can act for.
 *
 * A tax subject — not the login — is what owns documents, declarations and encrypted personal
 * data: each has its own data-encryption key, and every business query in the application is
 * scoped to one. That is why names and CNPs appear here and not on the profile screen, and why a
 * membership carries a role of its own.
 */
export default function SettingsSubjects(): JSX.Element {
    const { t } = useTranslation();

    const [subjects, setSubjects] = useState<TaxSubject[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloadToken, setReloadToken] = useState(0);
    const [adding, setAdding] = useState(false);

    const describeFailure = useCallback(
        (cause: unknown): string =>
            cause instanceof ApiError && cause.reason === 'network'
                ? t((d) => d.errors.network)
                : t((d) => d.errors.unexpected),
        [t],
    );

    useEffect(() => {
        let active = true;

        listSubjects()
            .then((loaded) => {
                if (active) {
                    setSubjects(loaded);
                    setFailed(false);
                }
            })
            .catch((cause: unknown) => {
                if (active) {
                    setSubjects([]);
                    setFailed(true);
                    toast.error(
                        t((d) => d.pages.settingsSubjects.loadFailed),
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

    const active = (subjects ?? []).filter((subject) => subject.status === 'ACTIVE').length;
    const owned = (subjects ?? []).filter((subject) => subject.role === 'OWNER').length;

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.settingsSubjects.title)}
                lede={t((d) => d.pages.settingsSubjects.description)}
                actions={
                    <div className="flex gap-1.5">
                        {failed ? (
                            <PanelButton
                                onClick={() => {
                                    setSubjects(null);
                                    setFailed(false);
                                    setReloadToken((token) => token + 1);
                                }}
                            >
                                {t((d) => d.pages.settingsSubjects.retry)}
                            </PanelButton>
                        ) : null}
                        <PanelButton
                            primary
                            onClick={() => {
                                setAdding(true);
                            }}
                        >
                            {t((d) => d.pages.settingsSubjects.add)}
                        </PanelButton>
                    </div>
                }
            >
                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.settingsSubjects.stats.total)}
                        value={subjects === null ? '—' : subjects.length}
                    />
                    <Stat
                        label={t((d) => d.pages.settingsSubjects.stats.active)}
                        value={subjects === null ? '—' : active}
                    />
                    <Stat
                        label={t((d) => d.pages.settingsSubjects.stats.owned)}
                        value={subjects === null ? '—' : owned}
                        sub={t((d) => d.pages.settingsSubjects.stats.ownedSub)}
                    />
                    <Stat
                        label={t((d) => d.pages.settingsSubjects.stats.encryption)}
                        value={<Status tone="ok">{t((d) => d.pages.settingsSubjects.stats.perSubject)}</Status>}
                        sub={t((d) => d.pages.settingsSubjects.stats.encryptionSub)}
                    />
                </StatGrid>

                <Panel
                    title={t((d) => d.pages.settingsSubjects.tableTitle)}
                    flush
                    footNote={t((d) => d.pages.settingsSubjects.addNote)}
                >
                    {subjects === null ? (
                        <div className="space-y-2 p-3">
                            {[0, 1].map((index) => (
                                <Skeleton key={index} className="tile h-11 w-full" />
                            ))}
                        </div>
                    ) : subjects.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.settingsSubjects.empty)}</p>
                    ) : (
                        <DataTable
                            head={
                                <>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.subject)}</th>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.type)}</th>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.role)}</th>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.status)}</th>
                                </>
                            }
                        >
                            {subjects.map((subject) => (
                                <tr key={subject.id}>
                                    <td>
                                        <Identity
                                            initials={initialsFor(subject.displayName)}
                                            name={subject.displayName}
                                            sub={subject.id}
                                        />
                                    </td>
                                    <td>
                                        <Pill>
                                            {t((d) => d.pages.settingsSubjects.types[subject.type] ?? subject.type)}
                                        </Pill>
                                    </td>
                                    <td>
                                        <Pill tone={subject.role === 'OWNER' ? 'accent' : 'neutral'}>
                                            {t((d) => d.pages.settingsSubjects.roles[subject.role] ?? subject.role)}
                                        </Pill>
                                    </td>
                                    <td>
                                        <Status tone={subject.status === 'ACTIVE' ? 'ok' : 'warn'}>
                                            {t(
                                                (d) =>
                                                    d.pages.settingsSubjects.statuses[subject.status] ?? subject.status,
                                            )}
                                        </Status>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            <AddSubjectDialog
                open={adding}
                onOpenChange={setAdding}
                onAdded={() => {
                    setAdding(false);
                    setSubjects(null);
                    setReloadToken((token) => token + 1);
                }}
                describeFailure={describeFailure}
            />
        </AppShell>
    );
}

/**
 * Registering an individual taxpayer.
 *
 * Every field here except the display name is encrypted with the new subject's own key the moment
 * it is stored, and none of it is ever logged — a rejected request echoes a field *name*, never a
 * value. The CNP is validated for shape only; whether it is a real CNP is a fiscal question this
 * form does not answer.
 */
function AddSubjectDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAdded: () => void;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
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

    const set = (key: keyof RegisterPfInput) => (value: string) => {
        setForm((current) => ({ ...current, [key]: value }));
        errors.clear(key);
    };

    const ready =
        form.displayName !== '' &&
        form.firstName !== '' &&
        form.lastName !== '' &&
        form.cnp !== '' &&
        form.birthDate !== '';

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
                        {t((d) => d.pages.settingsSubjects.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={!ready || saving}
                        onClick={() => {
                            void submit();
                        }}
                    >
                        {saving ? t((d) => d.pages.settingsSubjects.saving) : t((d) => d.pages.settingsSubjects.add)}
                    </PanelButton>
                </>
            }
        >
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

                <Field label={t((d) => d.pages.settingsSubjects.fields.cnp)} error={errors.get('cnp')}>
                    <PanelInput
                        icon={<IdCardIcon className="size-3.5" />}
                        inputMode="numeric"
                        autoComplete="off"
                        value={form.cnp}
                        onChange={(event) => {
                            set('cnp')(event.target.value);
                        }}
                    />
                </Field>

                <Field label={t((d) => d.pages.settingsSubjects.fields.birthDate)} error={errors.get('birthDate')}>
                    <PanelInput
                        icon={<CalendarIcon className="size-3.5" />}
                        type="date"
                        value={form.birthDate}
                        onChange={(event) => {
                            set('birthDate')(event.target.value);
                        }}
                    />
                </Field>

                <Field label={t((d) => d.pages.settingsSubjects.fields.countyCode)} error={errors.get('countyCode')}>
                    <PanelInput
                        icon={<MapIcon className="size-3.5" />}
                        autoComplete="off"
                        value={form.countyCode}
                        onChange={(event) => {
                            set('countyCode')(event.target.value);
                        }}
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

                <FieldSpan>
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
                </FieldSpan>
            </FieldGrid>

            <p className="note">{t((d) => d.pages.settingsSubjects.privacyNote)}</p>
        </PanelDialog>
    );
}
