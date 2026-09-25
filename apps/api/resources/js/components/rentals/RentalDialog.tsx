import { Banknote, Check, MapPinIcon, PercentIcon, UserIcon, X } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { listRentals, openRental, updateRental } from '../../api/rentalsApi';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import type { RentalForm } from '../../lib/rentalForm';
import type { RentalFormOptions, RentalSummary } from '../../types/rentals';
import { CURRENCIES } from '../../types/wallet';
import { PanelDate } from '../PanelDatePicker';
import { Field, FieldGrid, PanelButton, PanelDialog, PanelInput, PanelSelect } from '../panel';

const NO_OPTIONS: RentalFormOptions = { propertyTypes: [], tenantTypes: [], paymentFrequencies: [] };

function orFirst(value: string, choices: string[]): string {
    return value === '' ? (choices[0] ?? '') : value;
}

/**
 * Adding a rental — by hand, or from a contract document — and editing one.
 *
 * On an edit the rent, dates, currency and frequency are shown but disabled: a changed rent starts on
 * a date and an ending is its own act, and editing either in place would rewrite years already owed.
 */
export default function RentalDialog({
    subjectId,
    options,
    editing,
    initial,
    documentId,
    open,
    onOpenChange,
    onSaved,
}: {
    subjectId: string;
    /** The form's choices when the caller already has them; fetched otherwise. */
    options: RentalFormOptions | null;
    editing: RentalSummary | null;
    initial: RentalForm;
    /** The accepted contract this rental is made from. */
    documentId?: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSaved: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const errors = useFormErrors();
    const [fetchedOptions, setFetchedOptions] = useState<RentalFormOptions | null>(null);
    const [form, setForm] = useState<RentalForm>(initial);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (options !== null) {
            return;
        }

        let active = true;

        listRentals(subjectId).then(
            (list) => {
                if (active) {
                    setFetchedOptions(list.formOptions);
                }
            },
            () => {
                if (active) {
                    setFetchedOptions(NO_OPTIONS);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, options]);

    const choices = options ?? fetchedOptions ?? NO_OPTIONS;
    // Derived rather than written back into state, so a choice list arriving late cannot overwrite
    // something the person already picked.
    const values: RentalForm = {
        ...form,
        property_type: orFirst(form.property_type, choices.propertyTypes),
        tenant_type: orFirst(form.tenant_type, choices.tenantTypes),
        payment_frequency: orFirst(form.payment_frequency, choices.paymentFrequencies),
    };
    const termsLocked = editing !== null;
    const lockedHint = t((d) => d.pages.rentals.form.termsLocked);

    const set = (key: keyof RentalForm, value: string): void => {
        setForm((current) => ({ ...current, [key]: value }));
    };

    const submit = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        const description = {
            property_type: values.property_type,
            address: values.address,
            ownership_percent: values.ownership_percent,
            tenant_type: values.tenant_type,
            tenant_name: values.tenant_name,
        };

        try {
            if (editing === null) {
                await openRental(subjectId, {
                    ...description,
                    start_date: values.start_date,
                    end_date: values.end_date === '' ? null : values.end_date,
                    currency_code: values.currency_code,
                    // Major units in the form, minor units on the wire — rounded once, here.
                    rent_minor: Math.round(Number(values.rent) * 100),
                    payment_frequency: values.payment_frequency,
                    ...(documentId === undefined ? {} : { document_id: documentId }),
                });
            } else {
                await updateRental(subjectId, editing.id, description);
            }

            toast.success(editing === null ? t((d) => d.pages.rentals.added) : t((d) => d.pages.rentals.saved));
            onOpenChange(false);
            onSaved();
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                toast.error(
                    t((d) => d.pages.rentals.saveFailed),
                    { description: describeApiFailure(cause, t) },
                );
            }
        } finally {
            setSaving(false);
        }
    };

    const title =
        editing !== null
            ? t((d) => d.pages.rentals.edit)
            : documentId === undefined
              ? t((d) => d.pages.rentals.add)
              : t((d) => d.documentsReview.createRental);
    const description =
        editing !== null
            ? t((d) => d.pages.rentals.editHint)
            : documentId === undefined
              ? t((d) => d.pages.rentals.addHint)
              : t((d) => d.documentsReview.createRentalHint);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={description}
            size="lg"
            actions={
                <>
                    <PanelButton
                        onClick={() => {
                            onOpenChange(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.common.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={
                            saving ||
                            values.address.trim() === '' ||
                            values.tenant_name.trim() === '' ||
                            values.ownership_percent.trim() === '' ||
                            values.start_date === '' ||
                            values.rent.trim() === ''
                        }
                        onClick={() => {
                            void submit();
                        }}
                    >
                        <Check className="size-3.5" aria-hidden="true" />
                        {t((d) => d.common.save)}
                    </PanelButton>
                </>
            }
        >
            <FieldGrid>
                <Field
                    label={t((d) => d.pages.rentals.form.propertyType)}
                    htmlFor="rental-property-type"
                    error={errors.get('property_type')}
                >
                    <PanelSelect
                        id="rental-property-type"
                        value={values.property_type}
                        onValueChange={(value) => {
                            set('property_type', value);
                        }}
                        options={choices.propertyTypes.map((value) => ({
                            value,
                            label: t((d) => d.pages.rentals.propertyTypes[value] ?? value),
                        }))}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.address)}
                    htmlFor="rental-address"
                    error={errors.get('address')}
                >
                    <PanelInput
                        id="rental-address"
                        icon={<MapPinIcon className="size-3.5" />}
                        value={values.address}
                        onChange={(event) => {
                            set('address', event.target.value);
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.ownershipPercent)}
                    htmlFor="rental-share"
                    error={errors.get('ownership_percent')}
                    hint={t((d) => d.pages.rentals.form.ownershipPercentHint)}
                >
                    <PanelInput
                        id="rental-share"
                        icon={<PercentIcon className="size-3.5" />}
                        inputMode="decimal"
                        value={values.ownership_percent}
                        onChange={(event) => {
                            set('ownership_percent', event.target.value);
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.tenantType)}
                    htmlFor="rental-tenant-type"
                    error={errors.get('tenant_type')}
                >
                    <PanelSelect
                        id="rental-tenant-type"
                        value={values.tenant_type}
                        onValueChange={(value) => {
                            set('tenant_type', value);
                        }}
                        options={choices.tenantTypes.map((value) => ({
                            value,
                            label: t((d) => d.pages.rentals.tenantTypes[value] ?? value),
                        }))}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.tenantName)}
                    htmlFor="rental-tenant"
                    error={errors.get('tenant_name')}
                >
                    <PanelInput
                        id="rental-tenant"
                        icon={<UserIcon className="size-3.5" />}
                        value={values.tenant_name}
                        onChange={(event) => {
                            set('tenant_name', event.target.value);
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.startDate)}
                    htmlFor="rental-start"
                    error={errors.get('start_date')}
                    {...(termsLocked ? { hint: lockedHint } : {})}
                >
                    <PanelDate
                        block
                        disabled={termsLocked}
                        value={values.start_date === '' ? null : values.start_date}
                        onChange={(next) => {
                            set('start_date', next ?? '');
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.endDate)}
                    htmlFor="rental-end"
                    error={errors.get('end_date')}
                    hint={termsLocked ? lockedHint : t((d) => d.pages.rentals.form.endDateHint)}
                >
                    <PanelDate
                        block
                        disabled={termsLocked}
                        value={values.end_date === '' ? null : values.end_date}
                        onChange={(next) => {
                            set('end_date', next ?? '');
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.currency)}
                    htmlFor="rental-currency"
                    error={errors.get('currency_code')}
                    {...(termsLocked ? { hint: lockedHint } : {})}
                >
                    <PanelSelect
                        id="rental-currency"
                        disabled={termsLocked}
                        value={values.currency_code}
                        onValueChange={(value) => {
                            set('currency_code', value);
                        }}
                        options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.rent)}
                    htmlFor="rental-rent"
                    error={errors.get('rent_minor')}
                    hint={termsLocked ? lockedHint : t((d) => d.pages.rentals.form.rentHint)}
                >
                    <PanelInput
                        id="rental-rent"
                        icon={<Banknote className="size-3.5" />}
                        disabled={termsLocked}
                        type="number"
                        min={0}
                        step="0.01"
                        value={values.rent}
                        onChange={(event) => {
                            set('rent', event.target.value);
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.paymentFrequency)}
                    htmlFor="rental-frequency"
                    error={errors.get('payment_frequency')}
                    {...(termsLocked ? { hint: lockedHint } : {})}
                >
                    <PanelSelect
                        id="rental-frequency"
                        disabled={termsLocked}
                        value={values.payment_frequency}
                        onValueChange={(value) => {
                            set('payment_frequency', value);
                        }}
                        options={choices.paymentFrequencies.map((value) => ({
                            value,
                            label: t((d) => d.pages.wallet.frequencies[value] ?? value),
                        }))}
                    />
                </Field>
            </FieldGrid>
        </PanelDialog>
    );
}
