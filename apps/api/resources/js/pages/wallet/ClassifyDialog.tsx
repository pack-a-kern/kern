import { useState, type JSX } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { classifyTransaction } from '../../api/walletApi';
import { Field, PanelButton, PanelDialog, PanelInput, PanelSelect } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useCountries } from './useCountries';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatMinor, type TaxCategory, type TransactionSummary } from '../../types/wallet';

/** A row being edited. Amounts stay strings until save: a half-typed "4." is not a number yet. */
interface Draft {
    amount: string;
    category: string;
    country: string;
}

/** Romania, and the value a country field means when left alone. */
const DOMESTIC = 'RO';

/**
 * Says what a transaction was.
 *
 * ## Why this screen is the product
 *
 * A bank tells you 4.500 RON arrived from Popescu Ion. It cannot tell you whether that is rent,
 * a friend repaying a loan, or a dividend — and those are taxed completely differently, or not at
 * all. This is where that answer is given, and every figure a tax return ever states is built from
 * it.
 *
 * ## Why splits, not a single category
 *
 * One payment can be two things at once: 4.500 arriving of which 4.000 is rent and 500 the tenant
 * repaying a water bill. A single category would have to lose one of them, so the dialog opens with
 * one row and grows when the money needs more than one answer. The rows must add up to the
 * transaction exactly — they partition it rather than annotate it, and a remainder would be income
 * with no answer, silently missing from a return. The dialog shows what is left over and refuses to
 * save until it is zero.
 *
 * ## Why the country is a separate field
 *
 * Declarația Unică declares foreign income in a section of its own, per country *and* per type. So
 * `RENT` from Germany is a different row on the form from `RENT` from Romania, and a
 * `FOREIGN_INCOME` category would collapse a distinction the form makes. It defaults to Romania and
 * is only asked about when someone says the income was foreign.
 */
export default function ClassifyDialog(props: {
    subjectId: string;
    transaction: TransactionSummary;
    categories: TaxCategory[];
    onClose: () => void;
    onSaved: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const countries = useCountries();
    const [saving, setSaving] = useState(false);

    const transaction = props.transaction;

    // Seeded from what the transaction already says, so reopening it shows the current answer rather
    // than an empty form. A transaction nobody has answered for starts with one row holding the
    // whole amount, which is the common case: one payment, one meaning.
    //
    // The parent keys this component by transaction id, so opening a different row remounts it and
    // re-seeds — simpler, and more obviously correct, than syncing state to a prop in an effect.
    const [drafts, setDrafts] = useState<Draft[]>(() => seed(transaction));

    const total = transaction.amountMinor;
    const assigned = drafts.reduce((sum, draft) => sum + toMinor(draft.amount), 0);
    const remainder = total - assigned;
    const complete = drafts.length > 0 && remainder === 0 && drafts.every((draft) => draft.category !== '');

    const save = async (): Promise<void> => {
        setSaving(true);

        try {
            await classifyTransaction(
                props.subjectId,
                transaction.id,
                drafts.map((draft) => ({
                    amount_minor: toMinor(draft.amount),
                    category_code: draft.category,
                    // Romania is the absence of a country, not a value: the column means "somewhere
                    // other than here", and storing RO everywhere would make the foreign rows
                    // indistinguishable from the domestic ones by query.
                    country_code: draft.country === DOMESTIC ? null : draft.country,
                })),
            );

            toast.success(t((d) => d.pages.walletTransactions.classify.saved));
            props.onSaved();
        } catch (cause: unknown) {
            toast.error(t((d) => d.pages.walletTransactions.classify.failed), {
                description: describeApiFailure(cause, t),
            });
        } finally {
            setSaving(false);
        }
    };

    const clear = async (): Promise<void> => {
        setSaving(true);

        try {
            // An empty list is a legitimate answer: it returns the transaction to the worklist for
            // someone who realises they labelled it wrongly and does not yet know the right one.
            await classifyTransaction(props.subjectId, transaction.id, []);
            toast.success(t((d) => d.pages.walletTransactions.classify.cleared));
            props.onSaved();
        } catch (cause: unknown) {
            toast.error(t((d) => d.pages.walletTransactions.classify.failed), {
                description: describeApiFailure(cause, t),
            });
        } finally {
            setSaving(false);
        }
    };

    const update = (index: number, patch: Partial<Draft>): void => {
        setDrafts((current) => current.map((draft, at) => (at === index ? { ...draft, ...patch } : draft)));
    };

    return (
        <PanelDialog
            open
            onOpenChange={(next) => {
                if (!next) {
                    props.onClose();
                }
            }}
            size="lg"
            title={t((d) => d.pages.walletTransactions.classify.title)}
            description={t((d) => d.pages.walletTransactions.classify.hint)}
            actions={
                <>
                    <PanelButton onClick={props.onClose}>{t((d) => d.common.cancel)}</PanelButton>
                    {transaction.splits.length === 0 ? null : (
                        <PanelButton
                            disabled={saving}
                            onClick={() => {
                                void clear();
                            }}
                        >
                            {t((d) => d.pages.walletTransactions.classify.clear)}
                        </PanelButton>
                    )}
                    <PanelButton
                        primary
                        disabled={saving || !complete}
                        onClick={() => {
                            void save();
                        }}
                    >
                        {t((d) => d.common.save)}
                    </PanelButton>
                </>
            }
        >
            <div className="space-y-3">
                <div className="tile border p-3">
                    <div className="font-semibold">{transaction.description}</div>
                    <div className="note">
                        {new Date(transaction.bookedAt).toLocaleDateString()} ·{' '}
                        {formatMinor(transaction.amountMinor, transaction.currencyCode, locale)}
                        {transaction.counterparty === null ? '' : ` · ${transaction.counterparty}`}
                    </div>
                </div>

                {drafts.map((draft, index) => (
                    <div className="field-row" key={index}>
                        <Field label={t((d) => d.pages.walletTransactions.classify.category)}>
                            <PanelSelect
                                searchable
                                value={draft.category}
                                placeholder={t((d) => d.pages.walletTransactions.classify.choose)}
                                onValueChange={(next) => {
                                    update(index, { category: next });
                                }}
                                options={props.categories.map((category) => ({
                                    value: category.code,
                                    label: t((d) => d.pages.walletTransactions.categories[category.code] ?? category.code),
                                }))}
                            />
                        </Field>

                        <Field label={t((d) => d.pages.walletTransactions.classify.country)}>
                            <PanelSelect
                                searchable
                                value={draft.country}
                                onValueChange={(next) => {
                                    update(index, { country: next });
                                }}
                                options={countries.map((country) => ({
                                    value: country.code,
                                    label: country.name,
                                }))}
                            />
                        </Field>

                        <Field label={t((d) => d.pages.walletTransactions.classify.amount)}>
                            <PanelInput
                                inputMode="decimal"
                                value={draft.amount}
                                onChange={(event) => {
                                    update(index, { amount: event.target.value });
                                }}
                            />
                        </Field>

                        {/* Only when there is more than one: removing the last row would leave
                            nothing to answer with, which "Șterge clasificarea" already does more
                            clearly. */}
                        {drafts.length > 1 ? (
                            <PanelButton
                                variant="ghost"
                                aria-label={t((d) => d.common.delete)}
                                onClick={() => {
                                    setDrafts((current) => current.filter((_, at) => at !== index));
                                }}
                            >
                                <Trash2 className="size-3.5" aria-hidden="true" />
                            </PanelButton>
                        ) : null}
                    </div>
                ))}

                <div className="flex items-center justify-between gap-3">
                    <PanelButton
                        inline
                        onClick={() => {
                            setDrafts((current) => [
                                ...current,
                                // Pre-filled with what is still unaccounted for: the second row of a
                                // split is almost always "the rest of it".
                                { amount: fromMinor(remainder), category: '', country: DOMESTIC },
                            ]);
                        }}
                    >
                        <Plus className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.walletTransactions.classify.addSplit)}
                    </PanelButton>

                    <span className={remainder === 0 ? 'note' : 'text-destructive text-xs font-semibold'}>
                        {t((d) => d.pages.walletTransactions.classify.remainder).replace(
                            '{amount}',
                            formatMinor(remainder, transaction.currencyCode, locale),
                        )}
                    </span>
                </div>
            </div>
        </PanelDialog>
    );
}

/** The rows to start from: what the transaction already says, or one row holding all of it. */
function seed(transaction: TransactionSummary): Draft[] {
    if (transaction.splits.length === 0) {
        return [{ amount: fromMinor(transaction.amountMinor), category: '', country: DOMESTIC }];
    }

    return transaction.splits.map((split) => ({
        amount: fromMinor(split.amountMinor),
        category: split.categoryCode,
        country: split.countryCode ?? DOMESTIC,
    }));
}

/** Major units as typed → minor units, rounded once. A blank or half-typed field is zero. */
function toMinor(amount: string): number {
    const value = Number(amount.replace(',', '.'));

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

function fromMinor(amountMinor: number): string {
    return (amountMinor / 100).toFixed(2);
}
