import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { classifyTransaction } from '../../api/walletApi';
import { Field, FieldGrid, PanelButton, PanelDialog, PanelSelect } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { useCountries } from './useCountries';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatMinor, type TaxCategory, type TransactionSummary } from '../../types/wallet';

/** The answer being given: what it was, and where it came from. */
interface Draft {
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
 * ## One answer per transaction
 *
 * The whole amount gets one category. `transaction_splits` can hold several — one payment genuinely
 * can be two things, 4.000 rent and 500 the tenant repaying a water bill — and the server still
 * accepts that shape, so nothing has to be rebuilt if it is ever offered. It is not offered here:
 * splitting is rare, and a form that leads with it makes every ordinary answer look like a
 * calculation.
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
    // than an empty form.
    //
    // The parent keys this component by transaction id, so opening a different row remounts it and
    // re-seeds — simpler, and more obviously correct, than syncing state to a prop in an effect.
    const [draft, setDraft] = useState<Draft>(() => seed(transaction));

    const complete = draft.category !== '';

    const save = async (): Promise<void> => {
        setSaving(true);

        try {
            await classifyTransaction(
                props.subjectId,
                transaction.id,
                [
                    {
                        // The whole amount: one transaction, one answer.
                        amount_minor: transaction.amountMinor,
                        category_code: draft.category,
                        // Romania is the absence of a country, not a value: the column means
                        // "somewhere other than here", and storing RO everywhere would make the
                        // foreign rows indistinguishable from the domestic ones by query.
                        country_code: draft.country === DOMESTIC ? null : draft.country,
                    },
                ],
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

                <FieldGrid>
                    <Field label={t((d) => d.pages.walletTransactions.classify.category)}>
                        <PanelSelect
                            searchable
                            value={draft.category}
                            placeholder={t((d) => d.pages.walletTransactions.classify.choose)}
                            onValueChange={(next) => {
                                setDraft((current) => ({ ...current, category: next }));
                            }}
                            options={props.categories.map((category) => ({
                                value: category.code,
                                label: t(
                                    (d) => d.pages.walletTransactions.categories[category.code] ?? category.code,
                                ),
                            }))}
                        />
                    </Field>

                    {/* Declarația Unică declares foreign income in its own section, per country and
                        per type, so this is a field of its own rather than a category. It defaults
                        to Romania, which is the answer almost every time. */}
                    <Field label={t((d) => d.pages.walletTransactions.classify.country)}>
                        <PanelSelect
                            searchable
                            value={draft.country}
                            onValueChange={(next) => {
                                setDraft((current) => ({ ...current, country: next }));
                            }}
                            options={countries.map((country) => ({ value: country.code, label: country.name }))}
                        />
                    </Field>
                </FieldGrid>
            </div>
        </PanelDialog>
    );
}

/** The answer to start from: what the transaction already says, or an empty one. */
function seed(transaction: TransactionSummary): Draft {
    const existing = transaction.splits[0];

    return existing === undefined
        ? { category: '', country: DOMESTIC }
        : { category: existing.categoryCode, country: existing.countryCode ?? DOMESTIC };
}
