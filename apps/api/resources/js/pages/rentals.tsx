import {
    Activity,
    Banknote,
    CalendarRange,
    CalendarX2,
    Check,
    Coins,
    KeyRoundIcon,
    Pencil,
    PlusIcon,
    Trash2,
    UserRound,
    X,
} from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { changeRentalRent, deleteRental, endRental, listRentals } from '../api/rentalsApi';
import AppShell from '../components/AppShell';
import ConfirmDialog from '../components/ConfirmDialog';
import { PanelDate } from '../components/PanelDatePicker';
import {
    ActionGroup,
    CellLabel,
    DataTable,
    EmptyState,
    Field,
    FieldGrid,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelSelect,
    Pill,
    Status,
    TableSkeleton,
} from '../components/panel';
import RentalDialog from '../components/rentals/RentalDialog';
import { useFormErrors } from '../hooks/useFormErrors';
import { useTranslation } from '../i18n';
import { describeApiFailure } from '../lib/apiErrors';
import { formatDate, formatMinor } from '../lib/format';
import { emptyRentalForm, rentalFormFor, type RentalForm } from '../lib/rentalForm';
import { getActiveSubjectId } from '../lib/subjectStore';
import type { RentalList, RentalSummary } from '../types/rentals';

/** How many earlier years a row lists under this year's figure. */
const EARLIER_YEARS_SHOWN = 2;

/**
 * Rentals for the active taxpayer, and what each contract owes per year.
 *
 * ## Owed, not received
 *
 * The figure on each row is what the contract makes owed in the year — the taxpayer's share of the
 * rent for the periods falling due in it. It is the income a declaration counts, "indiferent de
 * momentul încasării chiriei" (Codul fiscal, art. 84), so nothing here asks whether the tenant paid,
 * and a rent payment recorded on the transactions screen never adds to it.
 */
export default function Rentals(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [loaded, setLoaded] = useState<RentalList | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);

    /** The add/edit dialog: the rental being edited, or null for a new one; closed when `form` is null. */
    const [dialog, setDialog] = useState<{ editing: RentalSummary | null; form: RentalForm } | null>(null);
    const [changing, setChanging] = useState<RentalSummary | null>(null);
    const [ending, setEnding] = useState<RentalSummary | null>(null);
    const [deleting, setDeleting] = useState<RentalSummary | null>(null);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listRentals(subjectId).then(
            (list) => {
                if (active) {
                    setLoaded(list);
                    setFailed(false);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                    setLoaded({
                        rentals: [],
                        formOptions: { propertyTypes: [], tenantTypes: [], paymentFrequencies: [] },
                    });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, reloadKey]);

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.rentals.title)}>
                    <Panel title={t((d) => d.pages.rentals.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    const reload = (): void => {
        setReloadKey((current) => current + 1);
    };

    const currentYear = new Date().getFullYear();
    const rentals = loaded?.rentals ?? null;

    return (
        <AppShell>
            <Page title={t((d) => d.pages.rentals.title)} lede={t((d) => d.pages.rentals.description)}>
                <Panel
                    title={t((d) => d.pages.rentals.title)}
                    description={t((d) => d.pages.rentals.description)}
                    icon={<KeyRoundIcon className="size-4" />}
                    flush
                    aside={
                        <PanelButton
                            primary
                            onClick={() => {
                                setDialog({ editing: null, form: emptyRentalForm() });
                            }}
                        >
                            <PlusIcon className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.rentals.add)}
                        </PanelButton>
                    }
                >
                    {rentals === null ? (
                        <TableSkeleton rows={3} />
                    ) : failed ? (
                        <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                    ) : rentals.length === 0 ? (
                        <EmptyState
                            icon={<KeyRoundIcon className="size-5" />}
                            title={t((d) => d.pages.rentals.emptyTitle)}
                            description={t((d) => d.pages.rentals.emptyHint)}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.rentals.columns.property)}</th>
                                    <th>{t((d) => d.pages.rentals.columns.tenant)}</th>
                                    <th>{t((d) => d.pages.rentals.columns.rent)}</th>
                                    <th>{t((d) => d.pages.rentals.columns.period)}</th>
                                    <th>
                                        {t((d) => d.pages.rentals.columns.owed).replace('{year}', String(currentYear))}
                                    </th>
                                    <th>{t((d) => d.pages.rentals.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {rentals.map((rental) => {
                                const active = rental.status === 'ACTIVE';
                                const owedThisYear = rental.years.find((entry) => entry.year === currentYear);
                                const earlier = rental.years
                                    .filter((entry) => entry.year < currentYear)
                                    .slice(0, EARLIER_YEARS_SHOWN);

                                return (
                                    <tr key={rental.id}>
                                        <td className="col-primary">
                                            <div className="font-semibold">{rental.address}</div>
                                            <div className="note">
                                                {t(
                                                    (d) =>
                                                        d.pages.rentals.propertyTypes[rental.propertyType] ??
                                                        rental.propertyType,
                                                )}
                                                {' · '}
                                                {t((d) => d.pages.rentals.share).replace(
                                                    '{percent}',
                                                    String(Number(rental.ownershipPercent)),
                                                )}
                                            </div>
                                            {rental.sourceDocumentId === null ? null : (
                                                <a
                                                    className="note underline"
                                                    href={`/documents/${encodeURIComponent(rental.sourceDocumentId)}/review`}
                                                >
                                                    {t((d) => d.pages.rentals.fromContract)}
                                                </a>
                                            )}
                                        </td>
                                        <td>
                                            <CellLabel icon={<UserRound className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.rentals.columns.tenant)}
                                            </CellLabel>
                                            {/* One element beside the mark, never three: the stacked
                                                cell is `space-between`, and a second and third child
                                                would be spread across the row instead of stacking
                                                against its right edge. */}
                                            <div className="min-w-0 text-right">
                                                <div className="truncate">{rental.tenantName}</div>
                                                <Pill>
                                                    {t(
                                                        (d) =>
                                                            d.pages.rentals.tenantTypes[rental.tenantType] ??
                                                            rental.tenantType,
                                                    )}
                                                </Pill>
                                            </div>
                                        </td>
                                        <td className="tabular-nums whitespace-nowrap">
                                            <CellLabel icon={<Banknote className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.rentals.columns.rent)}
                                            </CellLabel>
                                            <div className="text-right">
                                                {formatMinor(rental.rentMinor, rental.currencyCode, locale)}
                                                <div className="note">
                                                    {t(
                                                        (d) =>
                                                            d.pages.wallet.frequencies[rental.paymentFrequency] ??
                                                            rental.paymentFrequency,
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap">
                                            <CellLabel icon={<CalendarRange className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.rentals.columns.period)}
                                            </CellLabel>
                                            <div className="text-right">
                                                {formatDate(rental.startDate, locale)}
                                                <div className="note">
                                                    {rental.endDate === null
                                                        ? t((d) => d.pages.rentals.openEnded)
                                                        : formatDate(rental.endDate, locale)}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="tabular-nums whitespace-nowrap">
                                            <CellLabel icon={<Coins className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.rentals.columns.owed).replace(
                                                    '{year}',
                                                    String(currentYear),
                                                )}
                                            </CellLabel>
                                            <div className="text-right">
                                                {/* This taxpayer's share, owed whether or not it was paid.
                                                    A year with nothing due reads as zero: the contract
                                                    exists and owed nothing, which is itself an answer. */}
                                                {formatMinor(
                                                    owedThisYear?.shareMinor ?? 0,
                                                    rental.currencyCode,
                                                    locale,
                                                )}
                                                {earlier.map((entry) => (
                                                    <div key={entry.year} className="note">
                                                        {`${String(entry.year)}: ${formatMinor(entry.shareMinor, rental.currencyCode, locale)}`}
                                                    </div>
                                                ))}
                                            </div>
                                        </td>
                                        <td>
                                            <CellLabel icon={<Activity className="size-3.5" aria-hidden="true" />}>
                                                {t((d) => d.pages.rentals.columns.status)}
                                            </CellLabel>
                                            <Status tone={active ? 'ok' : 'idle'}>
                                                {active
                                                    ? t((d) => d.pages.rentals.active)
                                                    : t((d) => d.pages.rentals.endedStatus)}
                                            </Status>
                                        </td>
                                        <td className="col-end text-right">
                                            <div className="flex justify-end">
                                                <ActionGroup
                                                    // Four labelled actions are most of a phone's
                                                    // width; behind one menu there, as themselves
                                                    // everywhere else.
                                                    collapse
                                                    /*
                                                        A mark and the shortest word that is still
                                                        true, rather than the dialog's own title: the
                                                        heading of the sheet that opens says "Change
                                                        rent", and repeating it on the button that
                                                        opens it spent most of the row on four
                                                        sentences. Delete keeps its mark alone — it is
                                                        the one action a reader already knows by its
                                                        shape, and the row has nothing else red in it.
                                                    */
                                                    actions={[
                                                        {
                                                            key: 'edit',
                                                            icon: <Pencil className="size-3.5" aria-hidden="true" />,
                                                            label: t((d) => d.common.edit),
                                                            onClick: () => {
                                                                setDialog({
                                                                    editing: rental,
                                                                    form: rentalFormFor(rental),
                                                                });
                                                            },
                                                        },
                                                        // An ended rental's terms are the record of what it
                                                        // owed; there is no rent left to change or end.
                                                        ...(active
                                                            ? [
                                                                  {
                                                                      key: 'rent',
                                                                      icon: (
                                                                          <Banknote
                                                                              className="size-3.5"
                                                                              aria-hidden="true"
                                                                          />
                                                                      ),
                                                                      label: t((d) => d.pages.rentals.actions.rent),
                                                                      onClick: () => {
                                                                          setChanging(rental);
                                                                      },
                                                                  },
                                                                  {
                                                                      key: 'end',
                                                                      icon: (
                                                                          <CalendarX2
                                                                              className="size-3.5"
                                                                              aria-hidden="true"
                                                                          />
                                                                      ),
                                                                      label: t((d) => d.pages.rentals.actions.end),
                                                                      onClick: () => {
                                                                          setEnding(rental);
                                                                      },
                                                                  },
                                                              ]
                                                            : []),
                                                        {
                                                            key: 'delete',
                                                            label: <Trash2 className="size-3.5" aria-hidden="true" />,
                                                            title: t((d) => d.common.delete),
                                                            tone: 'destructive' as const,
                                                            onClick: () => {
                                                                setDeleting(rental);
                                                            },
                                                        },
                                                    ]}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {dialog === null ? null : (
                <RentalDialog
                    subjectId={subjectId}
                    options={loaded?.formOptions ?? null}
                    editing={dialog.editing}
                    initial={dialog.form}
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setDialog(null);
                        }
                    }}
                    onSaved={reload}
                />
            )}

            {changing === null ? null : (
                <ChangeRentDialog
                    subjectId={subjectId}
                    rental={changing}
                    frequencies={loaded?.formOptions.paymentFrequencies ?? []}
                    onClose={() => {
                        setChanging(null);
                    }}
                    onSaved={reload}
                />
            )}

            {ending === null ? null : (
                <EndRentalDialog
                    subjectId={subjectId}
                    rental={ending}
                    onClose={() => {
                        setEnding(null);
                    }}
                    onSaved={reload}
                />
            )}

            {deleting === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setDeleting(null);
                        }
                    }}
                    title={t((d) => d.pages.rentals.deleteTitle)}
                    description={t((d) => d.pages.rentals.deleteHint)}
                    detail={<p className="note">{deleting.address}</p>}
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await deleteRental(subjectId, deleting.id);
                            toast.success(t((d) => d.pages.rentals.deleted));
                            setDeleting(null);
                            reload();
                        } catch (cause: unknown) {
                            toast.error(
                                t((d) => d.common.deleteFailed),
                                { description: describeApiFailure(cause, t) },
                            );

                            throw cause;
                        }
                    }}
                />
            )}
        </AppShell>
    );
}

/** A new rent from a date. The periods before it keep the rent they were owed at. */
function ChangeRentDialog({
    subjectId,
    rental,
    frequencies,
    onClose,
    onSaved,
}: {
    subjectId: string;
    rental: RentalSummary;
    frequencies: string[];
    onClose: () => void;
    onSaved: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const errors = useFormErrors();
    const [effectiveFrom, setEffectiveFrom] = useState('');
    const [rent, setRent] = useState((rental.rentMinor / 100).toFixed(2));
    const [frequency, setFrequency] = useState(rental.paymentFrequency);
    const [saving, setSaving] = useState(false);

    const submit = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            await changeRentalRent(subjectId, rental.id, {
                effective_from: effectiveFrom,
                rent_minor: Math.round(Number(rent) * 100),
                payment_frequency: frequency,
            });
            toast.success(t((d) => d.pages.rentals.rentChanged));
            onClose();
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

    return (
        <PanelDialog
            open
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
            title={t((d) => d.pages.rentals.changeRent)}
            description={t((d) => d.pages.rentals.changeRentHint)}
            actions={
                <>
                    <PanelButton onClick={onClose}>
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.common.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={saving || effectiveFrom === '' || rent.trim() === ''}
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
                    label={t((d) => d.pages.rentals.form.effectiveFrom)}
                    htmlFor="rent-effective-from"
                    error={errors.get('effective_from')}
                >
                    <PanelDate
                        block
                        value={effectiveFrom === '' ? null : effectiveFrom}
                        onChange={(next) => {
                            setEffectiveFrom(next ?? '');
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.rent)}
                    htmlFor="rent-amount"
                    error={errors.get('rent_minor')}
                    hint={rental.currencyCode}
                >
                    <PanelInput
                        id="rent-amount"
                        icon={<Banknote className="size-3.5" />}
                        type="number"
                        min={0}
                        step="0.01"
                        value={rent}
                        onChange={(event) => {
                            setRent(event.target.value);
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.rentals.form.paymentFrequency)}
                    htmlFor="rent-frequency"
                    error={errors.get('payment_frequency')}
                >
                    <PanelSelect
                        id="rent-frequency"
                        value={frequency}
                        onValueChange={setFrequency}
                        options={frequencies.map((value) => ({
                            value,
                            label: t((d) => d.pages.wallet.frequencies[value] ?? value),
                        }))}
                    />
                </Field>
            </FieldGrid>
        </PanelDialog>
    );
}

/** The rental ends on a date; rent stops being owed from that day. */
function EndRentalDialog({
    subjectId,
    rental,
    onClose,
    onSaved,
}: {
    subjectId: string;
    rental: RentalSummary;
    onClose: () => void;
    onSaved: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const errors = useFormErrors();
    const [endDate, setEndDate] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            await endRental(subjectId, rental.id, endDate);
            toast.success(t((d) => d.pages.rentals.ended));
            onClose();
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

    return (
        <PanelDialog
            open
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
            title={t((d) => d.pages.rentals.end)}
            description={t((d) => d.pages.rentals.endHint)}
            actions={
                <>
                    <PanelButton onClick={onClose}>
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.common.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={saving || endDate === ''}
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
                    label={t((d) => d.pages.rentals.form.endDate)}
                    htmlFor="rental-end-date"
                    error={errors.get('end_date')}
                >
                    <PanelDate
                        block
                        value={endDate === '' ? null : endDate}
                        onChange={(next) => {
                            setEndDate(next ?? '');
                        }}
                    />
                </Field>
            </FieldGrid>
        </PanelDialog>
    );
}
