import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteTransaction, listAccounts, listTransactions, recordTransaction } from '../../api/walletApi';
import AppShell from '../../components/AppShell';
import ConfirmDialog from '../../components/ConfirmDialog';
import {
    DataTable,
    Field,
    FieldGrid,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelSelect,
    Pill,
    Toolbar,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import { getActiveSubjectId } from '../../lib/subjectStore';
import {
    CURRENCIES,
    type AccountSummary,
    type TransactionPage,
    type TransactionSummary,
    formatMinor,
} from '../../types/wallet';

/**
 * Money moving through the accounts.
 *
 * This is the table `App\Domain\Rental\Services\Matching\TransactionMatchCandidateFinder` reads to
 * answer "is this 2,500 RON the rent for Apt 12?" — which is why the tax-status column exists.
 * Without transactions, rental income is asserted; with them, it is matched against money that
 * actually arrived.
 *
 * Everything here is entered by hand today. The underlying table carries `import_batch_id` and a
 * blind-indexed external id because it was designed for bank-statement import, where a batch groups
 * one statement and the external id stops a re-import duplicating rows. That importer does not
 * exist, so `source` is always MANUAL.
 */
export default function WalletTransactions(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [loaded, setLoaded] = useState<{ accountId: string; page: TransactionPage } | null>(null);
    const [accounts, setAccounts] = useState<AccountSummary[]>([]);
    const [accountId, setAccountId] = useState('');
    const [failed, setFailed] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);

    const errors = useFormErrors();
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<TransactionSummary | null>(null);
    const [saving, setSaving] = useState(false);
    // Typed explicitly: `CURRENCIES[0]` is a const-asserted tuple, so inference would pin
    // `currency_code` to the literal 'RON' and reject every other code the select offers.
    const [form, setForm] = useState<{
        account_id: string;
        amount: string;
        currency_code: string;
        booked_at: string;
        value_at: string;
        description: string;
        counterparty: string;
    }>({
        account_id: '',
        amount: '',
        currency_code: CURRENCIES[0],
        booked_at: '',
        value_at: '',
        description: '',
        counterparty: '',
    });

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listTransactions(subjectId, accountId === '' ? {} : { accountId }).then(
            (page) => {
                if (active) {
                    setLoaded({ accountId, page });
                    setFailed(false);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                }
            },
        );

        // The account list feeds both the filter and the form's select; a transaction cannot exist
        // without an account, so an empty list is a real answer rather than a loading state.
        listAccounts(subjectId).then(
            (page) => {
                if (active) {
                    setAccounts(page.accounts);
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
    }, [subjectId, accountId, reloadKey]);

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.walletTransactions.title)}>
                    <Panel title={t((d) => d.pages.walletTransactions.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    const page = loaded !== null && loaded.accountId === accountId ? loaded.page : null;

    const submit = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            await recordTransaction(subjectId, {
                account_id: form.account_id,
                // Signed minor units: the form takes a signed major amount, so money out is typed
                // with a minus rather than chosen from a direction control that could disagree.
                amount_minor: Math.round(Number(form.amount) * 100),
                currency_code: form.currency_code,
                booked_at: form.booked_at,
                description: form.description,
                ...(form.counterparty === '' ? {} : { counterparty: form.counterparty }),
                ...(form.value_at === '' ? {} : { value_at: form.value_at }),
            });

            toast.success(t((d) => d.pages.walletTransactions.added));
            setAdding(false);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                toast.error(t((d) => d.pages.walletTransactions.addFailed));
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.walletTransactions.title)}
                lede={t((d) => d.pages.walletTransactions.description)}
            >
                <Panel
                    title={t((d) => d.pages.walletTransactions.title)}
                    footNote={
                        page?.capped === true
                            ? t((d) => d.pages.walletTransactions.capped).replace('{limit}', String(page.limit))
                            : undefined
                    }
                    flush
                >
                    <Toolbar>
                        <PanelButton
                            primary
                            disabled={accounts.length === 0}
                            onClick={() => {
                                errors.reset();
                                setForm((current) => ({
                                    ...current,
                                    account_id: accounts[0]?.id ?? '',
                                    currency_code: accounts[0]?.currencyCode ?? CURRENCIES[0],
                                    booked_at: new Date().toISOString().slice(0, 10),
                                }));
                                setAdding(true);
                            }}
                        >
                            {t((d) => d.pages.walletTransactions.add)}
                        </PanelButton>
                        <PanelSelect
                            className="filter-select"
                            value={accountId}
                            onValueChange={setAccountId}
                            placeholder={t((d) => d.pages.walletTransactions.allAccounts)}
                            emptyLabel={t((d) => d.pages.walletTransactions.allAccounts)}
                            options={accounts.map((account) => ({ value: account.id, label: account.displayName }))}
                        />
                    </Toolbar>

                    {page === null ? (
                        failed ? (
                            <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                        ) : (
                            <TableSkeleton />
                        )
                    ) : page.transactions.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.walletTransactions.empty)}</p>
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.walletTransactions.columns.date)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.account)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.description)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.amount)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {page.transactions.map((transaction) => (
                                <tr key={transaction.id}>
                                    <td className="col-primary whitespace-nowrap tabular-nums">
                                        {new Date(transaction.bookedAt).toLocaleDateString()}
                                    </td>
                                    <td className="col-hide">{transaction.accountName}</td>
                                    <td>
                                        <div>{transaction.description}</div>
                                        {transaction.counterparty === null ? null : (
                                            <div className="note">{transaction.counterparty}</div>
                                        )}
                                    </td>
                                    {/* Money out reads red; the sign is the source of truth, not a
                                        separate direction column that could contradict it. */}
                                    <td
                                        className={`tabular-nums whitespace-nowrap${
                                            transaction.amountMinor < 0 ? ' text-destructive' : ''
                                        }`}
                                    >
                                        {formatMinor(transaction.amountMinor, transaction.currencyCode, locale)}
                                    </td>
                                    <td>
                                        <Pill
                                            tone={
                                                transaction.classificationStatus === 'CLASSIFIED' ? 'accent' : 'neutral'
                                            }
                                        >
                                            {t(
                                                (d) =>
                                                    d.pages.walletTransactions.statuses[
                                                        transaction.classificationStatus
                                                    ] ?? transaction.classificationStatus,
                                            )}
                                        </Pill>
                                    </td>
                                    <td className="col-end text-right">
                                        <PanelButton
                                            variant="ghost"
                                            onClick={() => {
                                                setDeleting(transaction);
                                            }}
                                        >
                                            {t((d) => d.common.delete)}
                                        </PanelButton>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {deleting === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setDeleting(null);
                        }
                    }}
                    title={t((d) => d.pages.walletTransactions.deleteTitle)}
                    description={t((d) => d.pages.walletTransactions.deleteHint)}
                    detail={
                        <p className="note">
                            {deleting.description} ·{' '}
                            <span className="tabular-nums">
                                {formatMinor(deleting.amountMinor, deleting.currencyCode, locale)}
                            </span>
                        </p>
                    }
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await deleteTransaction(subjectId, deleting.id);
                            toast.success(t((d) => d.pages.walletTransactions.deleted));
                            setDeleting(null);
                            setReloadKey((current) => current + 1);
                        } catch (cause: unknown) {
                            toast.error(t((d) => d.common.deleteFailed));

                            throw cause;
                        }
                    }}
                />
            )}

            <PanelDialog
                open={adding}
                onOpenChange={setAdding}
                title={t((d) => d.pages.walletTransactions.add)}
                description={t((d) => d.pages.walletTransactions.addHint)}
                size="lg"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setAdding(false);
                            }}
                        >
                            {t((d) => d.common.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={
                                saving ||
                                form.account_id === '' ||
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
                        label={t((d) => d.pages.walletTransactions.form.account)}
                        htmlFor="tx-account"
                        error={errors.get('account_id')}
                    >
                        <PanelSelect
                            id="tx-account"
                            value={form.account_id}
                            onValueChange={(value) => {
                                const account = accounts.find((candidate) => candidate.id === value);

                                setForm((current) => ({
                                    ...current,
                                    account_id: value,
                                    // The currency follows the account: a transaction in a currency
                                    // the account does not hold is a data-entry mistake, not a case
                                    // to support.
                                    currency_code: account?.currencyCode ?? current.currency_code,
                                }));
                            }}
                            options={accounts.map((account) => ({
                                value: account.id,
                                label: `${account.displayName} (${account.currencyCode})`,
                            }))}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.currency)}
                        htmlFor="tx-currency"
                        error={errors.get('currency_code')}
                    >
                        <PanelSelect
                            id="tx-currency"
                            value={form.currency_code}
                            onValueChange={(value) => {
                                setForm((current) => ({ ...current, currency_code: value }));
                            }}
                            options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.amount)}
                        htmlFor="tx-amount"
                        error={errors.get('amount_minor')}
                        hint={t((d) => d.pages.walletTransactions.form.amountHint)}
                    >
                        <PanelInput
                            id="tx-amount"
                            type="number"
                            step="0.01"
                            value={form.amount}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, amount: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.bookedAt)}
                        htmlFor="tx-booked"
                        error={errors.get('booked_at')}
                    >
                        <PanelInput
                            id="tx-booked"
                            type="date"
                            value={form.booked_at}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, booked_at: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.valueAt)}
                        htmlFor="tx-value"
                        error={errors.get('value_at')}
                        hint={t((d) => d.pages.walletTransactions.form.valueAtHint)}
                    >
                        <PanelInput
                            id="tx-value"
                            type="date"
                            value={form.value_at}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, value_at: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.counterparty)}
                        htmlFor="tx-counterparty"
                        error={errors.get('counterparty')}
                        hint={t((d) => d.pages.walletTransactions.form.counterpartyHint)}
                    >
                        <PanelInput
                            id="tx-counterparty"
                            value={form.counterparty}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, counterparty: event.target.value }));
                            }}
                        />
                    </Field>

                    <FieldSpanDescription
                        label={t((d) => d.pages.walletTransactions.form.description)}
                        error={errors.get('description')}
                        value={form.description}
                        onChange={(value) => {
                            setForm((current) => ({ ...current, description: value }));
                        }}
                    />
                </FieldGrid>
            </PanelDialog>
        </AppShell>
    );
}

/** The description spans both columns — it is the one free-text field and the longest. */
function FieldSpanDescription(props: {
    label: string;
    error: string | undefined;
    value: string;
    onChange: (value: string) => void;
}): JSX.Element {
    return (
        <div className="sm:col-span-2">
            <Field label={props.label} htmlFor="tx-description" error={props.error}>
                <PanelInput
                    id="tx-description"
                    value={props.value}
                    onChange={(event) => {
                        props.onChange(event.target.value);
                    }}
                />
            </Field>
        </div>
    );
}

function TableSkeleton(): JSX.Element {
    return (
        <div className="space-y-2 p-3">
            {[0, 1, 2, 3].map((index) => (
                <Skeleton key={index} className="tile h-9 w-full" />
            ))}
        </div>
    );
}
