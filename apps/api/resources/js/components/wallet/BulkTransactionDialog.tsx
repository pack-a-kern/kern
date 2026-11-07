import { Shapes } from 'lucide-react';
import { useMemo, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { classifyTransaction, updateTransaction } from '../../api/walletApi';
import { Field, FieldGrid, PanelButton, PanelDialog, PanelSelect } from '../panel';
import CounterpartyInput from './CounterpartyInput';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { applyInTurn } from '../../lib/applyInTurn';
import { categoriesFor, type TaxCategory, type TransactionSummary } from '../../types/wallet';

/** Which bulk change the dialog makes. */
export type BulkChange = 'payee' | 'category';

/**
 * The payee, or the tax category, of every ticked transaction at once.
 *
 * Each row is changed through the endpoint that changes one — the same checks, the same refusals (a
 * locked year, a generated row), the same entry in the history — one after the other, stopping at
 * the first the server refuses ({@link applyInTurn}).
 *
 * A category is one split for the whole amount, as the edit form gives it: a row split across several
 * categories gets this one for all of it, and "Classify later" returns every row to the worklist. The
 * categories offered are those that fit every ticked row — money in and money out have different
 * ones, so a selection of both is offered only the categories that fit either.
 */
export default function BulkTransactionDialog(props: {
    change: BulkChange;
    subjectId: string;
    transactions: readonly TransactionSummary[];
    categories: readonly TaxCategory[];
    countries: readonly { code: string; name: string }[];
    onClose: () => void;
    /** Told how many rows were changed, once the dialog has finished — every one, or up to a refusal. */
    onApplied: (done: number) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [counterparty, setCounterparty] = useState('');
    const [category, setCategory] = useState('');
    const [country, setCountry] = useState('RO');
    const [working, setWorking] = useState(false);
    const count = props.transactions.length;

    const offered = useMemo(() => {
        const directions = new Set(
            props.transactions.map((transaction) => (transaction.amountMinor < 0 ? 'out' : 'in')),
        );
        const [only] = directions;

        return directions.size === 1 && only !== undefined
            ? categoriesFor([...props.categories], only)
            : props.categories.filter((candidate) => candidate.direction === 'either');
    }, [props.transactions, props.categories]);

    const apply = async (): Promise<void> => {
        setWorking(true);

        const { done, failure } = await applyInTurn(props.transactions, async (transaction) => {
            if (props.change === 'payee') {
                await updateTransaction(props.subjectId, transaction.id, {
                    counterparty: counterparty.trim() === '' ? null : counterparty.trim(),
                });

                return;
            }

            await classifyTransaction(
                props.subjectId,
                transaction.id,
                category === ''
                    ? []
                    : [
                          {
                              amount_minor: transaction.amountMinor,
                              category_code: category,
                              country_code: country === 'RO' ? null : country,
                          },
                      ],
            );
        });

        setWorking(false);

        if (failure === undefined) {
            toast.success(t((d) => d.pages.walletTransactions.bulk.done).replace('{count}', String(done)));
        } else {
            toast.error(
                t((d) => d.pages.walletTransactions.bulk.stopped)
                    .replace('{done}', String(done))
                    .replace('{count}', String(count)),
                { description: describeApiFailure(failure, t) },
            );
        }

        props.onApplied(done);
    };

    const title =
        props.change === 'payee'
            ? t((d) => d.pages.walletTransactions.bulk.payeeTitle)
            : t((d) => d.pages.walletTransactions.bulk.categoryTitle);

    return (
        <PanelDialog
            open
            onOpenChange={(next) => {
                if (!next && !working) {
                    props.onClose();
                }
            }}
            title={title}
            description={(props.change === 'payee'
                ? t((d) => d.pages.walletTransactions.bulk.payeeHint)
                : t((d) => d.pages.walletTransactions.bulk.categoryHint)
            ).replace('{count}', String(count))}
            actions={
                <>
                    <PanelButton variant="outline" onClick={props.onClose} disabled={working}>
                        {t((d) => d.common.cancel)}
                    </PanelButton>
                    <PanelButton primary onClick={() => void apply()} disabled={working}>
                        {working
                            ? t((d) => d.pages.walletTransactions.bulk.applying)
                            : t((d) => d.pages.walletTransactions.bulk.apply).replace('{count}', String(count))}
                    </PanelButton>
                </>
            }
        >
            <FieldGrid>
                {props.change === 'payee' ? (
                    <Field
                        label={t((d) => d.pages.walletTransactions.form.counterparty)}
                        htmlFor="bulk-counterparty"
                        hint={t((d) => d.pages.walletTransactions.bulk.payeeEmpty)}
                    >
                        <CounterpartyInput
                            id="bulk-counterparty"
                            subjectId={props.subjectId}
                            value={counterparty}
                            onChange={setCounterparty}
                        />
                    </Field>
                ) : (
                    <>
                        <Field label={t((d) => d.pages.walletTransactions.classify.category)}>
                            <PanelSelect
                                icon={<Shapes className="size-3.5" aria-hidden="true" />}
                                searchable
                                value={category}
                                placeholder={t((d) => d.pages.walletTransactions.classify.later)}
                                emptyLabel={t((d) => d.pages.walletTransactions.classify.later)}
                                onValueChange={setCategory}
                                options={offered.map((candidate) => ({
                                    value: candidate.code,
                                    label: t(
                                        (d) => d.pages.walletTransactions.categories[candidate.code] ?? candidate.code,
                                    ),
                                }))}
                            />
                        </Field>
                        {/* Only once a category is chosen: the country qualifies the answer, as on the form. */}
                        {category === '' ? null : (
                            <Field label={t((d) => d.pages.walletTransactions.classify.country)}>
                                <PanelSelect
                                    searchable
                                    value={country}
                                    onValueChange={setCountry}
                                    options={props.countries.map((candidate) => ({
                                        value: candidate.code,
                                        label: candidate.name,
                                    }))}
                                />
                            </Field>
                        )}
                    </>
                )}
            </FieldGrid>
        </PanelDialog>
    );
}
