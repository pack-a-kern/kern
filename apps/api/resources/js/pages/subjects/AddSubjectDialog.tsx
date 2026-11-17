import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import {
    AlertTriangle,
    ArrowRight,
    Building2Icon,
    CalendarIcon,
    CheckCircle2,
    GlobeIcon,
    IdCardIcon,
    Loader2,
    Lock,
    MapPinIcon,
    Plus,
    ScanLine,
    TagIcon,
    Upload,
    UserIcon,
    X,
} from 'lucide-react';
import { toast } from 'sonner';

import {
    type IdCardFaceBox,
    type RegisterPfInput,
    getIdCardReading,
    inspectCnp,
    registerPfSubject,
    startIdCardRead,
    uploadSubjectAvatar,
} from '../../api/accountApi';
import { FileDrop } from '../../components/FileDrop';
import { Field, FieldGrid, FieldSpan, PanelButton, PanelDialog, PanelInput, PanelSelect } from '../../components/panel';
import { Checkbox } from '../../components/ui/checkbox';
import { useTranslation } from '../../i18n';
import { useFormErrors } from '../../hooks/useFormErrors';
import { subscribeToIdCardReads } from '../../lib/echo';
import { useSharedAuth } from '../../lib/sharedProps';
import { IdCardAvatarCropper } from './IdCardAvatarCropper';
import { useCounties } from './useCounties';

/**
 * The country every taxpayer here is registered under.
 *
 * The server decides it (`config/subjects.php`); this is the same value stated on the form so a
 * person can see what will be stored. If the two ever disagree the server wins, which is why this
 * is not sent.
 */
const COUNTRY = 'RO';

type ScanState = 'idle' | 'reading' | 'done' | 'failed';

export default function AddSubjectDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAdded: () => void;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const counties = useCounties();
    const userId = useSharedAuth()?.user.id ?? '';
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
     * Scanning an identity card to fill the form (ADR 0028). Entirely optional: the manual CNP path
     * below is unchanged and remains the source of truth. The scan fills the CNP (and stashes the
     * name and address for the second step), and the card image is kept in the browser only, to crop
     * the avatar from — never uploaded to us beyond the single read.
     */
    const [consent, setConsent] = useState(false);
    const [scan, setScan] = useState<ScanState>('idle');
    const [cardUrl, setCardUrl] = useState<string | null>(null);
    const [faceBox, setFaceBox] = useState<IdCardFaceBox | null>(null);
    const [avatarBlob, setAvatarBlob] = useState<Blob | null>(null);
    const [stash, setStash] = useState<{ firstName: string; lastName: string; address: string } | null>(null);
    const awaitingToken = useRef<string | null>(null);
    const cardUrlRef = useRef<string | null>(null);

    const resolveReading = useCallback(async (token: string): Promise<void> => {
        // Resolve once: the server deletes the result on read, so a later nudge or the fallback timer
        // must not fetch again and find nothing.
        if (awaitingToken.current !== token) {
            return;
        }

        awaitingToken.current = null;

        const reading = await getIdCardReading(token);

        if (reading === null || reading.status === 'failed') {
            setScan('failed');

            return;
        }

        const { fields } = reading;

        // A read that comes back with neither a CNP nor a name is not a usable identity card — a blurry
        // photo, the wrong document, the back of the card. Treated as a failed read so the screen asks
        // for another image rather than silently filling nothing.
        if (fields.cnp === null && fields.firstName === null && fields.lastName === null) {
            setScan('failed');

            return;
        }

        setForm((current) => ({ ...current, cnp: fields.cnp ?? current.cnp }));
        setStash({
            firstName: fields.firstName ?? '',
            lastName: fields.lastName ?? '',
            address: fields.address ?? '',
        });
        setFaceBox(fields.faceBox);
        setScan('done');
    }, []);

    // One private channel for the person; the nudge says a read finished, and this fetches it once.
    useEffect(() => {
        if (userId === '') {
            return undefined;
        }

        return subscribeToIdCardReads(userId, (token) => {
            if (token === awaitingToken.current) {
                void resolveReading(token);
            }
        });
    }, [userId, resolveReading]);

    // The card image lives only as an object URL in this browser; let it go when the dialog does.
    useEffect(
        () => () => {
            if (cardUrlRef.current !== null) {
                URL.revokeObjectURL(cardUrlRef.current);
            }
        },
        [],
    );

    const onCardChosen = async (file: File): Promise<void> => {
        if (cardUrlRef.current !== null) {
            URL.revokeObjectURL(cardUrlRef.current);
        }

        const url = URL.createObjectURL(file);
        cardUrlRef.current = url;
        setCardUrl(url);
        setScan('reading');
        errors.reset();

        let token: string;

        try {
            token = await startIdCardRead(file, consent);
        } catch {
            setScan('failed');

            return;
        }

        if (token === '') {
            setScan('failed');

            return;
        }

        awaitingToken.current = token;

        // The socket is the normal path; this is the backstop for a nudge that never arrives.
        window.setTimeout(() => {
            if (awaitingToken.current === token) {
                void resolveReading(token);
            }
        }, 130_000);
    };

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
                // What the card said, applied as the second step opens — still every field editable.
                ...(stash === null
                    ? {}
                    : {
                          firstName: stash.firstName !== '' ? stash.firstName : current.firstName,
                          lastName: stash.lastName !== '' ? stash.lastName : current.lastName,
                          address: stash.address !== '' ? stash.address : current.address,
                          displayName: `${stash.firstName} ${stash.lastName}`.trim() || current.displayName,
                      }),
            }));

            if (stash !== null && (stash.firstName !== '' || stash.lastName !== '')) {
                setDerivedName(false);
            }
        } catch (cause: unknown) {
            // The server names what is wrong with the number — a check digit, a date that does not
            // exist, a series this application does not serve. Shown on the field, not in a toast.
            errors.set('cnp', props.describeFailure(cause));
        } finally {
            setChecking(false);
        }
    };

    const [checking, setChecking] = useState(false);
    const [details, setDetails] = useState<{ birthDate: string; countyCode: string } | null>(null);

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
            const subjectId = await registerPfSubject(form);

            // The avatar is a convenience, not part of the registration: if setting it fails, the
            // taxpayer still exists and a picture can be added from its screen.
            if (subjectId !== '' && avatarBlob !== null) {
                try {
                    await uploadSubjectAvatar(subjectId, new File([avatarBlob], 'avatar.png', { type: 'image/png' }));
                } catch {
                    toast.warning(t((d) => d.pages.settingsSubjects.idCard.avatarFailed));
                }
            }

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

    // Let the person try a different card: forget the image, the result, and the crop, back to idle.
    const resetScan = (): void => {
        if (cardUrlRef.current !== null) {
            URL.revokeObjectURL(cardUrlRef.current);
            cardUrlRef.current = null;
        }

        awaitingToken.current = null;
        setCardUrl(null);
        setFaceBox(null);
        setAvatarBlob(null);
        setStash(null);
        setScan('idle');
    };

    // The read's own area, by state: busy, done (with a way back), the drop area once consent is
    // given, or a locked stand-in until it is. Kept out of the markup so the states do not nest.
    let scanArea: JSX.Element;

    if (scan === 'reading') {
        scanArea = (
            <p className="idcard-status idcard-status--busy">
                <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                {t((d) => d.pages.settingsSubjects.idCard.reading)}
            </p>
        );
    } else if (scan === 'done') {
        scanArea = (
            <div className="idcard-done">
                <p className="idcard-status idcard-status--ok">
                    <CheckCircle2 className="size-4" aria-hidden="true" />
                    <span className="flex-1">{t((d) => d.pages.settingsSubjects.idCard.scanned)}</span>
                </p>
                <PanelButton
                    variant="outline"
                    inline
                    onClick={() => {
                        resetScan();
                    }}
                >
                    <Upload className="size-3.5" aria-hidden="true" />
                    {t((d) => d.pages.settingsSubjects.idCard.scanAgain)}
                </PanelButton>
            </div>
        );
    } else if (consent) {
        scanArea = (
            <FileDrop
                id="idcard-scan-file"
                compact
                accept={['.jpg', '.jpeg', '.png']}
                title={t((d) => d.pages.settingsSubjects.idCard.dropTitle)}
                choose={t((d) => d.pages.settingsSubjects.idCard.choose)}
                onFiles={(files) => {
                    const file = files[0];

                    if (file !== undefined) {
                        void onCardChosen(file);
                    }
                }}
            />
        );
    } else {
        scanArea = (
            <div className="idcard-locked">
                <Lock className="size-5" aria-hidden="true" />
                <span>{t((d) => d.pages.settingsSubjects.idCard.enable)}</span>
            </div>
        );
    }

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
                    <div className="idcard-scan">
                        <div className="idcard-scan-head">
                            <span className="idcard-scan-badge" aria-hidden="true">
                                <ScanLine className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <p className="idcard-scan-title">{t((d) => d.pages.settingsSubjects.idCard.title)}</p>
                                <p className="note">{t((d) => d.pages.settingsSubjects.idCard.hint)}</p>
                            </div>
                        </div>

                        <label className="idcard-consent" data-on={consent ? '' : undefined} htmlFor="idcard-consent">
                            <Checkbox
                                id="idcard-consent"
                                checked={consent}
                                className="mt-0.5"
                                onCheckedChange={(value) => {
                                    setConsent(value === true);
                                }}
                            />
                            <span>{t((d) => d.pages.settingsSubjects.idCard.consent)}</span>
                        </label>

                        {scanArea}

                        {scan === 'failed' && (
                            <p className="idcard-status idcard-status--bad">
                                <AlertTriangle className="size-4" aria-hidden="true" />
                                {t((d) => d.pages.settingsSubjects.idCard.readFailed)}
                            </p>
                        )}
                    </div>

                    <div className="idcard-or">
                        <span>{t((d) => d.pages.settingsSubjects.idCard.or)}</span>
                    </div>

                    <Field
                        label={t((d) => d.pages.settingsSubjects.fields.cnp)}
                        hint={t((d) => d.pages.settingsSubjects.fields.cnpHint)}
                        error={errors.get('cnp')}
                    >
                        <PanelInput
                            icon={<IdCardIcon className="size-3.5" />}
                            inputMode="numeric"
                            autoComplete="off"
                            value={form.cnp}
                            // While a card is being read, the CNP it will fill is not the person's to type
                            // over: the field waits for the answer rather than racing it.
                            disabled={scan === 'reading'}
                            onChange={(event) => {
                                set('cnp')(event.target.value);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && form.cnp !== '' && scan !== 'reading') {
                                    event.preventDefault();
                                    void checkCnp();
                                }
                            }}
                        />
                    </Field>

                    <PanelButton
                        primary
                        inline
                        disabled={form.cnp === '' || checking || scan === 'reading'}
                        onClick={() => {
                            void checkCnp();
                        }}
                    >
                        <ArrowRight className="size-3.5" aria-hidden="true" />
                        {checking
                            ? t((d) => d.pages.settingsSubjects.checkingCnp)
                            : t((d) => d.pages.settingsSubjects.continueLabel)}
                    </PanelButton>
                </div>
            ) : (
                <FieldGrid>
                    {cardUrl !== null && (
                        <FieldSpan>
                            <Field label={t((d) => d.pages.settingsSubjects.idCard.avatarLabel)}>
                                <div className="idcard-crop-frame">
                                    <IdCardAvatarCropper
                                        imageUrl={cardUrl}
                                        initialBox={faceBox}
                                        onCrop={setAvatarBlob}
                                    />
                                </div>
                            </Field>
                        </FieldSpan>
                    )}

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
