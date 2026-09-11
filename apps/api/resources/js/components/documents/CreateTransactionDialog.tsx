import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { createTransactionFromDocument } from '../../api/documentsApi';
import { listAccounts } from '../../api/walletApi';
import { useEffect } from 'react';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import type { DocumentExtractionSummary, DocumentSummary } from '../../types/documents';
import type { AccountSummary } from '../../types/wallet';
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
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState(() => prefill(extraction, document));

    useEffect(() => {
        let active = true;

        listAccounts(subjectId).then(
            (page) => {
                if (active) {
                    // Closed accounts are excluded: money cannot move through one, so offering it
                    // would only produce a transaction nobody can explain.
                    setAccounts(page.accounts.filter((account) => !account.closed));
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

    const submit = async (): Promise<void> => {
        if (account === undefined) {
            return;
        }

        setSaving(true);
        errors.reset();

        try {
            // Major units in the form, minor units on the wire — rounded once, here, so no
            // fractional bani ever reaches the API. The direction is applied as the sign.
            const magnitude = Math.round(Number(form.amount) * 100);

            await createTransactionFromDocument(subjectId, document.id, {
                account_id: account.id,
                amount_minor: form.direction === 'out' ? -magnitude : magnitude,
                currency_code: account.currencyCode,
                booked_at: form.booked_at,
                description: form.description,
                ...(form.counterparty === '' ? {} : { counterparty: form.counterparty }),
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
                            label: `${candidate.displayName} · ${candidate.currencyCode}`,
                        }))}
                    />
                </Field>

                <Field label={t((d) => d.documentsReview.transactionDirection)}>
                    {/* The sign is the direction. A receipt defaults to money out, but an invoice
                        can be either and a refund is money in, so this is chosen rather than
                        inferred from the document type. */}
                    <ButtonGroup
                        value={form.direction}
                        onChange={(value) => {
                            setForm((current) => ({ ...current, direction: value }));
                        }}
                        options={[
                            { value: 'out', label: t((d) => d.documentsReview.directionOut) },
                            { value: 'in', label: t((d) => d.documentsReview.directionIn) },
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
                    label={t((d) => d.documentsReview.transactionDate)}
                    htmlFor="transaction-date"
                    error={errors.get('booked_at')}
                >
                    <PanelInput
                        id="transaction-date"
                        type="date"
                        value={form.booked_at}
                        onChange={(event) => {
                            setForm((current) => ({ ...current, booked_at: event.target.value }));
                        }}
                    />
                </Field>

                <Field
                    label={t((d) => d.documentsReview.transactionDescription)}
                    htmlFor="transaction-description"
                    error={errors.get('description')}
                >
                    <PanelInput
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
    direction: 'out' | 'in';
    amount: string;
    booked_at: string;
    description: string;
    counterparty: string;
}

/**
 * Starting values, read from the document's own proposal.
 *
 * Field names come from `ExtractionSchema`, so a reading by a parser, by a model, or typed by a
 * human all pre-fill identically. Anything the document did not yield starts empty rather than
 * guessed — an empty box a person fills is honest; a plausible wrong value they skim past is not.
 */
function prefill(extraction: DocumentExtractionSummary | null, document: DocumentSummary): TransactionForm {
    const fields = extraction?.proposedFields ?? {};
    const read = (name: string): string => {
        const value = fields[name];

        return typeof value === 'string' ? value : '';
    };

    const merchant = read('merchant');

    return {
        account_id: '',
        // A receipt is money going out; anything else is the user's call.
        direction: document.type === 'RECEIPT' ? 'out' : 'out',
        amount: read('amount'),
        // Falls back to the day the document arrived, which is closer than nothing and visibly a
        // date the person can correct.
        booked_at: read('date') || document.receivedAt.slice(0, 10),
        description: merchant === '' ? document.filename : merchant,
        counterparty: merchant,
    };
}
