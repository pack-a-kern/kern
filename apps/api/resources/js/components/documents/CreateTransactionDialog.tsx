import { AlignLeft, Banknote, Building2, Check, X } from 'lucide-react';
import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { createTransactionFromDocument } from '../../api/documentsApi';
import { listAccounts, listTaxCategories } from '../../api/walletApi';
import { useEffect } from 'react';
import { PanelDate } from '../PanelDatePicker';
import { useFormErrors } from '../../hooks/useFormErrors';
import { currencyFrom, entryAt, groupOf, readString, subjectPaymentIndex } from '../../lib/readingFields';
import { useCurrencies } from '../../lib/sharedProps';
import { useTranslation } from '../../i18n';
import type { DocumentExtractionSummary, DocumentSummary } from '../../types/documents';
import {
    categoriesFor,
    categoryFor,
    DIRECTION_ICONS,
    isStreamKind,
    signedMinor,
    sourceName,
    type AccountSummary,
    type MoneyDirection,
    type TaxCategory,
} from '../../types/wallet';
import { ButtonGroup, Field, FieldGrid, PanelButton, PanelDialog, PanelInput, PanelSelect } from '../panel';

/**
 * Turning a document into a wallet transaction, with a person in the middle.
 *
 * ## Why the form is pre-filled rather than submitted for you
 *
 * A parser proposes; a person confirms. The values below start from the best available reading of
 * the document, and what reaches the ledger is what the person actually saw in these boxes — not a
 * set the server re-derived after a click. It also means a document *no* parser could read still
 * becomes a transaction here: the fields simply start empty and are typed from the page on screen.
 *
 * ## Direction is a choice, not a guess
 *
 * The amount is stored signed, and the sign *is* the direction — a separate field would let the two
 * disagree. A receipt is money out and defaults that way, but an invoice can be either and a refund
 * on a receipt is money in, so the toggle is explicit rather than inferred from the document type.
 */
export default function CreateTransactionDialog({
    subjectId,
    document,
    extraction,
    open,
    onOpenChange,
    onCreated,
}: {
    subjectId: string;
    document: DocumentSummary;
    /** The reading to pre-fill from, or null when nothing could read this document. */
    extraction: DocumentExtractionSummary | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const errors = useFormErrors();

    const [accounts, setAccounts] = useState<AccountSummary[]>([]);
    // From the catalogue the endpoint validates against, not a list kept here — the same vocabulary
    // the transactions screen's classification offers, so the two cannot disagree.
    const [categories, setCategories] = useState<TaxCategory[]>([]);
    const [saving, setSaving] = useState(false);
    const currencies = useCurrencies();
    const [form, setForm] = useState(() => prefill(extraction, document, currencies));

    useEffect(() => {
        let active = true;

        listTaxCategories(subjectId).then(
            (loaded) => {
                if (active) {
                    setCategories(loaded);
                    // The reading may propose a code the catalogue does not have, or one that cannot move
                    // the way the payment did — a model answers whatever it likes. Replaced rather than
                    // sent, or save would be refused; money out starts from the catalogue's preselection.
                    setForm((current) => ({
                        ...current,
                        category: categoryFor(loaded, current.category, current.direction),
                    }));
                }
            },
            () => {
                if (active) {
                    setCategories([]);
                }
            },
        );

        listAccounts(subjectId).then(
            (page) => {
                if (active) {
                    // Only sources that receive transactions. A cash holding is one declared value
                    // and a deposit's entries come from its terms, so neither can take a document's.
                    setAccounts(page.accounts.filter((account) => isStreamKind(account.kind)));
                }
            },
            () => {
                if (active) {
                    setAccounts([]);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId]);

    const account = accounts.find((candidate) => candidate.id === form.account_id) ?? accounts[0];

    // The account's own currency, where it has one. A broker or a crypto source holds several and
    // carries none, which is exactly when the choice below matters.
    const accountCurrency = account?.currencyCode ?? null;
    const currencyWarning =
        accountCurrency !== null && form.currency_code !== accountCurrency
            ? t((d) => d.documentsReview.currencyMismatch)
                  .replace('{document}', form.currency_code)
                  .replace('{account}', accountCurrency)
            : undefined;

    const submit = async (): Promise<void> => {
        if (account === undefined) {
            return;
        }

        setSaving(true);
        errors.reset();

        try {
            await createTransactionFromDocument(subjectId, document.id, {
                account_id: account.id,
                // Major units in the form, signed minor units on the wire: the direction is the sign.
                amount_minor: signedMinor(form.amount, form.direction),
                // Chosen, not inherited. This used to send the source's currency, falling back to
                // whichever code happened to be first in the list — so a dividend paid in USD into
                // a multi-currency source was recorded in the alphabetically first currency.
                currency_code: form.currency_code,
                booked_at: form.booked_at,
                description: form.description,
                ...(form.counterparty === '' ? {} : { counterparty: form.counterparty }),
                // One split for the whole amount, so the transaction is stored classified. Omitted
                // when nothing was chosen, which leaves it UNCLASSIFIED — a real state, not a gap.
                ...(form.category === '' ? {} : { category: form.category }),
                ...(extraction === null ? {} : { document_extraction_id: extraction.id }),
            });

            toast.success(t((d) => d.documentsReview.transactionCreated));
            onOpenChange(false);
            onCreated();
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                toast.error(t((d) => d.documentsReview.transactionFailed));
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t((d) => d.documentsReview.createTransaction)}
            description={t((d) => d.documentsReview.createTransactionHint)}
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
                            account === undefined ||
                            form.amount.trim() === '' ||
                            form.booked_at === '' ||
                            form.description.trim() === ''
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
                    label={t((d) => d.documentsReview.transactionAccount)}
                    htmlFor="transaction-account"
                    error={errors.get('account_id')}
                    hint={
                        accounts.length === 0
                            ? t((d) => d.documentsReview.transactionNoAccounts)
                            : t((d) => d.documentsReview.transactionCurrencyHint).replace(
                                  '{currency}',
                                  account?.currencyCode ?? '',
                              )
                    }
                >
                    <PanelSelect
                        id="transaction-account"
                        value={account?.id ?? ''}
                        onValueChange={(value) => {
                            setForm((current) => ({ ...current, account_id: value }));
                        }}
                        options={accounts.map((candidate) => ({
                            value: candidate.id,
                            // A source may hold several currencies and carry no default,
                            // so only name it when there is one.
                            label:
                                candidate.currencyCode === null
                                    ? sourceName(
                                          candidate,
                                          t((d) => d.pages.wallet.generalSource),
                                      )
                                    : `${sourceName(
                                          candidate,
                                          t((d) => d.pages.wallet.generalSource),
                                      )} · ${candidate.currencyCode}`,
                        }))}
                    />
                </Field>

                <Field label={t((d) => d.documentsReview.transactionDirection)}>
                    {/* The sign is the direction. A receipt defaults to money out, but an invoice
                        can be either and a refund is money in, so this is chosen rather than
                        inferred from the document type. */}
                    <ButtonGroup
                        stretch
                        value={form.direction}
                        onChange={(value) => {
                            setForm((current) => ({
                                ...current,
                                direction: value,
                                category: categoryFor(categories, current.category, value),
                            }));
                        }}
                        options={[
                            {
                                value: 'out',
                                label: t((d) => d.documentsReview.directionOut),
                                icon: DIRECTION_ICONS.out,
                            },
                            { value: 'in', label: t((d) => d.documentsReview.directionIn), icon: DIRECTION_ICONS.in },
                        ]}
                    />
                </Field>

                <Field
                    label={t((d) => d.documentsReview.transactionAmount)}
                    htmlFor="transaction-amount"
                    error={errors.get('amount_minor')}
                    hint={t((d) => d.documentsReview.transactionAmountHint)}
                >
                    <PanelInput
                        icon={<Banknote className="size-3.5" />}
                        id="transaction-amount"
                        type="number"
                        min={0}
                        step="0.01"
                        value={form.amount}
                        onChange={(event) => {
                            setForm((current) => ({ ...current, amount: event.target.value }));
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.walletTransactions.form.currency)}
                    htmlFor="transaction-currency"
                    error={errors.get('currency_code')}
                    // Only when the two actually disagree — otherwise it is a sentence restating
                    // the select next to it.
                    hint={currencyWarning}
                >
                    <PanelSelect
                        id="transaction-currency"
                        value={form.currency_code}
                        onValueChange={(value) => {
                            setForm((current) => ({ ...current, currency_code: value }));
                        }}
                        options={currencies.map((code) => ({ value: code, label: code }))}
                    />
                </Field>

                <Field
                    label={t((d) => d.pages.walletTransactions.classify.category)}
                    htmlFor="transaction-category"
                    error={errors.get('category')}
                >
                    <PanelSelect
                        id="transaction-category"
                        value={form.category}
                        placeholder={t((d) => d.pages.walletTransactions.classify.later)}
                        onValueChange={(value) => {
                            setForm((current) => ({ ...current, category: value }));
                        }}
                        options={categoriesFor(categories, form.direction).map((category) => ({
                            value: category.code,
                            label: t((d) => d.pages.walletTransactions.categories[category.code] ?? category.code),
                        }))}
                    />
                </Field>

                <Field
                    label={t((d) => d.documentsReview.transactionDate)}
                    htmlFor="transaction-date"
                    error={errors.get('booked_at')}
                >
                    <PanelDate
                        block
                        value={form.booked_at === '' ? null : form.booked_at}
                        onChange={(next) => {
                            setForm((current) => ({ ...current, booked_at: next ?? '' }));
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.documentsReview.transactionDescription)}
                    htmlFor="transaction-description"
                    error={errors.get('description')}
                >
                    <PanelInput
                        icon={<AlignLeft className="size-3.5" />}
                        id="transaction-description"
                        value={form.description}
                        onChange={(event) => {
                            setForm((current) => ({ ...current, description: event.target.value }));
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.documentsReview.transactionCounterparty)}
                    htmlFor="transaction-counterparty"
                    error={errors.get('counterparty')}
                >
                    <PanelInput
                        icon={<Building2 className="size-3.5" />}
                        id="transaction-counterparty"
                        value={form.counterparty}
                        onChange={(event) => {
                            setForm((current) => ({ ...current, counterparty: event.target.value }));
                        }}
                    />
                </Field>
            </FieldGrid>
        </PanelDialog>
    );
}

interface TransactionForm {
    account_id: string;
    direction: MoneyDirection;
    amount: string;
    currency_code: string;
    /** '' means "not classified", which is a real answer and the transaction's default state. */
    category: string;
    booked_at: string;
    description: string;
    counterparty: string;
}

/**
 * The category the reading proposed, as a starting point. Checked against the catalogue once that
 * has loaded — see the effect above — because a model may answer a code nobody has defined.
 */
function categoryFrom(payment: Record<string, unknown>): string {
    return readString(payment, 'category').toUpperCase();
}

/**
 * Starting values, read from the document's own proposal.
 *
 * Field names come from `ExtractionSchema`, so a reading by a parser, by a model, or typed by a
 * human all pre-fill identically. Anything the document did not yield starts empty rather than
 * guessed — an empty box a person fills is honest; a plausible wrong value they skim past is not.
 *
 * The money comes from the first `payments` entry, not from a top-level amount: a document says
 * what it obliges, and a document obliging more than one payment is ordinary (an ANAF obligation
 * with several lines, a dividend decision with several beneficiaries). Only the first is offered
 * here because this dialog builds one transaction; the rest are what the bulk import is for.
 */
function prefill(
    extraction: DocumentExtractionSummary | null,
    document: DocumentSummary,
    offered: string[],
): TransactionForm {
    const fields = extraction?.proposedFields ?? {};
    const index = subjectPaymentIndex(extraction);
    const payment = entryAt(fields, 'payments', index);
    // Which side of this payment the subject is on, worked out on the server against their own
    // name — the reading itself only says who paid and who was paid, as printed.
    const parties = extraction?.paymentParties[index] ?? { direction: null, counterparty: null };
    const issuer = readString(groupOf(fields, 'issuer'), 'name');

    return {
        account_id: '',
        // 'out' where the subject was found on neither side of the payment: a default the person
        // can flip, never a claim. Guessing the other way round would be equally arbitrary.
        direction: parties.direction === 'IN' ? 'in' : 'out',
        // What the document was actually paid in, where it says so — the amount above is in that
        // currency, and inheriting the account's instead is how 211588 USD became 211588 RON.
        currency_code: currencyFrom(payment, offered),
        category: categoryFrom(payment),
        // What was actually handed over. `amount_stated` is the same money in the currency the
        // document computes in, and belongs to a conversion this form does not do.
        amount: readString(payment, 'amount_paid'),
        // The payment's own date where it has one, else the document's, else the day it arrived —
        // each closer than nothing and visibly a date the person can correct.
        booked_at: readString(payment, 'date') || readString(fields, 'date') || document.receivedAt.slice(0, 10),
        // What the document *is*, in its own words. This used to be the counterparty, so a receipt
        // read "Kaufland" in both boxes and explained nothing.
        description: readString(payment, 'description') || readString(fields, 'description') || document.filename,
        counterparty: parties.counterparty ?? issuer,
    };
}
