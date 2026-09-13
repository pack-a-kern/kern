import { useEffect, useMemo, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { classifyTransaction, deleteTransaction, listAccounts, listTransactions, recordTransaction, updateTransaction } from '../../api/walletApi';
import { AlignLeftIcon, UserIcon, ArrowLeftRight, PlusIcon, Trash2 } from 'lucide-react';
import AppShell from '../../components/AppShell';
import ConfirmDialog from '../../components/ConfirmDialog';
import {
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
    Toolbar,
} from '../../components/panel';
import BulkActions from '../../components/BulkActions';
import { PanelDate } from '../../components/PanelDatePicker';
import Checkbox from '../../components/Checkbox';
import Pagination, { PAGE_SIZES } from '../../components/Pagination';
import { DropdownMenuItem } from '../../components/ui/dropdown-menu';
import { Skeleton } from '../../components/ui/skeleton';
import { useRowSelection } from '../../hooks/useRowSelection';
import { useCountries } from './useCountries';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { cn } from '../../lib/utils';
import { getActiveSubjectId } from '../../lib/subjectStore';
import {
    CURRENCIES,
    isStreamKind,
    sourceName,
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
/**
 * The add/edit form's fields.
 *
 * Declared rather than inferred: `CURRENCIES[0]` is a const-asserted tuple, so inference would pin
 * `currency_code` to the literal 'RON' and reject every other code the select offers.
 */
interface TransactionForm {
    account_id: string;
    amount: string;
    currency_code: string;
    booked_at: string;
    description: string;
    counterparty: string;
    category_code: string;
    country_code: string;
}

function emptyTransactionForm(): TransactionForm {
    return {
        account_id: '',
        amount: '',
        currency_code: CURRENCIES[0],
        booked_at: '',
        description: '',
        counterparty: '',
        category_code: '',
        // Romania: the answer almost every time, and the absence of a country when stored.
        country_code: 'RO',
    };
}

export default function WalletTransactions(): JSX.Element {
    const { t, locale } = useTranslation();
    const countries = useCountries();
    const subjectId = getActiveSubjectId();

    const [loaded, setLoaded] = useState<{ accountId: string; page: TransactionPage } | null>(null);
    const [accounts, setAccounts] = useState<AccountSummary[]>([]);
    /** The transaction being corrected, or null when the dialog is recording a new one. */
    const [editing, setEditing] = useState<TransactionSummary | null>(null);
    /** `all` | `unclassified` | `classified` — the list is a worklist, so it can be narrowed to it. */
    const [classification, setClassification] = useState('all');
    const [pageNumber, setPageNumber] = useState(1);
    const [pageSize, setPageSize] = useState<number>(PAGE_SIZES[1]);
    const [bulkDeleting, setBulkDeleting] = useState(false);
    const [confirmingBulkDelete, setConfirmingBulkDelete] = useState(false);
    const [accountId, setAccountId] = useState('');
    const [failed, setFailed] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);

    const errors = useFormErrors();
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<TransactionSummary | null>(null);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState<TransactionForm>(emptyTransactionForm());

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

        // Only sources that receive transactions. A cash holding is one declared value and a term
        // deposit's entries are computed from its terms, so offering either here produced a
        // transaction that detached the ledger from the thing it describes — the server refuses it
        // now, and the form stops asking. The list feeds both the filter and the form's select.
        listAccounts(subjectId).then(
            (page) => {
                if (active) {
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
    }, [subjectId, accountId, reloadKey]);

    const page = loaded !== null && loaded.accountId === accountId ? loaded.page : null;

    const rows = (page?.transactions ?? []).filter((transaction) =>
        classification === 'all'
            ? true
            : classification === 'unclassified'
              ? transaction.splits.length === 0
              : transaction.splits.length > 0,
    );

    /*
     * Paged in the browser, over what the server already sent.
     *
     * The endpoint returns a capped window in one request, so asking it for a page would be a round
     * trip to slice an array that is already here — and the classification filter above narrows the
     * same array, so paging anywhere else would make the two disagree about what is on screen.
     */
    const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));
    const currentPage = Math.min(pageNumber, pageCount);
    const paged = rows.slice((currentPage - 1) * pageSize, currentPage * pageSize);
    const pageIds = useMemo(() => paged.map((transaction) => transaction.id), [paged]);
    const selection = useRowSelection(pageIds);

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


    // Filtered here rather than on the server: the list is already a capped window the server sent
    // in one go, so narrowing it is a property of what is on screen, not another request.

    const deleteSelected = async (): Promise<void> => {
        setBulkDeleting(true);

        let removed = 0;

        try {
            // One request each: there is no bulk endpoint, and inventing one would skip the audit
            // event every single delete already writes. Sequential so a partial failure leaves a
            // coherent list rather than an unknown number of rows gone.
            for (const id of selection.ids) {
                await deleteTransaction(subjectId, id);
                removed += 1;
            }

            toast.success(t((d) => d.pages.walletTransactions.deleted));
        } catch (cause: unknown) {
            toast.error(t((d) => d.common.deleteFailed), { description: describeApiFailure(cause, t) });
        } finally {
            setBulkDeleting(false);
            setConfirmingBulkDelete(false);

            if (removed > 0) {
                selection.clear();
                setReloadKey((current) => current + 1);
            }
        }
    };

    const submit = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            if (editing !== null) {
                // Correcting one. The source and the currency are absent on purpose: a transaction
                // recorded against the wrong source is a different transaction, and moving it would
                // reinterpret a balance that has already been read.
                await updateTransaction(subjectId, editing.id, {
                    amount_minor: Math.round(Number(form.amount) * 100),
                    booked_at: form.booked_at,
                    description: form.description,
                    counterparty: form.counterparty === '' ? null : form.counterparty,
                });

                // And what it was, in the same save. Two endpoints because the transaction and its
                // answer are different records, but one action as far as a person is concerned —
                // the alternative was a second button that opened a second dialog to change one
                // field the first dialog was already showing.
                await classifyTransaction(
                    subjectId,
                    editing.id,
                    form.category_code === ''
                        ? []
                        : [
                              {
                                  amount_minor: Math.round(Number(form.amount) * 100),
                                  category_code: form.category_code,
                                  country_code: form.country_code === 'RO' ? null : form.country_code,
                              },
                          ],
                );

                toast.success(t((d) => d.pages.walletTransactions.saved));
                setEditing(null);
                setForm(emptyTransactionForm());
                setReloadKey((current) => current + 1);

                return;
            }

            await recordTransaction(subjectId, {
                account_id: form.account_id,
                // Signed minor units: the form takes a signed major amount, so money out is typed
                // with a minus rather than chosen from a direction control that could disagree.
                amount_minor: Math.round(Number(form.amount) * 100),
                currency_code: form.currency_code,
                booked_at: form.booked_at,
                description: form.description,
                ...(form.counterparty === '' ? {} : { counterparty: form.counterparty }),
                // Answered here rather than in a second dialog afterwards: someone typing a
                // transaction already knows what it was. Romania is the absence of a country.
                ...(form.category_code === '' ? {} : { category_code: form.category_code }),
                ...(form.category_code === '' || form.country_code === 'RO'
                    ? {}
                    : { country_code: form.country_code }),
            });

            toast.success(t((d) => d.pages.walletTransactions.added));
            setAdding(false);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                toast.error(
                    editing === null
                        ? t((d) => d.pages.walletTransactions.addFailed)
                        : t((d) => d.pages.walletTransactions.saveFailed),
                );
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
                    description={t((d) => d.pages.walletTransactions.description)}
                    icon={<ArrowLeftRight className="size-4" />}
                    flush
                    aside={
                        <PanelButton
                            primary
                            inline
                            disabled={accounts.length === 0}
                            onClick={() => {
                                errors.reset();
                                setForm({
                                    ...emptyTransactionForm(),
                                    account_id: accounts[0]?.id ?? '',
                                    currency_code: accounts[0]?.currencyCode ?? CURRENCIES[0],
                                    booked_at: new Date().toISOString().slice(0, 10),
                                });
                                setAdding(true);
                            }}
                        >
                            <PlusIcon className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.walletTransactions.add)}
                        </PanelButton>
                    }
                    foot={
                        <Pagination
                            page={currentPage}
                            pageSize={pageSize}
                            total={rows.length}
                            unit={t((d) => d.pages.walletTransactions.unit)}
                            onPageChange={setPageNumber}
                            onPageSizeChange={(size) => {
                                setPageSize(size);
                                setPageNumber(1);
                            }}
                        />
                    }
                >
                    <Toolbar>
                        <PanelSelect
                            className="filter-select"
                            value={accountId}
                            onValueChange={setAccountId}
                            placeholder={t((d) => d.pages.walletTransactions.allAccounts)}
                            emptyLabel={t((d) => d.pages.walletTransactions.allAccounts)}
                            options={accounts.map((account) => ({
                                value: account.id,
                                label: sourceName(account, t((d) => d.pages.wallet.generalSource)),
                            }))}
                        />
                        {/* What turns a list into a worklist: "Neclasificate" is the only view that
                            says what is still to do, and it is the reason this screen exists. */}
                        <BulkActions count={selection.size} disabled={bulkDeleting}>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => {
                                    setConfirmingBulkDelete(true);
                                }}
                            >
                                <Trash2 className="size-4" />
                                {t((d) => d.pages.walletTransactions.deleteSelected)}
                            </DropdownMenuItem>
                        </BulkActions>
                        <PanelSelect
                            className="filter-select"
                            value={classification}
                            onValueChange={(next) => {
                                setClassification(next === '' ? 'all' : next);
                            }}
                            options={[
                                { value: 'all', label: t((d) => d.pages.walletTransactions.classify.allStatuses) },
                                {
                                    value: 'unclassified',
                                    label: t((d) => d.pages.walletTransactions.classify.unclassified),
                                },
                                {
                                    value: 'classified',
                                    label: t((d) => d.pages.walletTransactions.classify.classified),
                                },
                            ]}
                        />
                    </Toolbar>

                    {page === null ? (
                        failed ? (
                            <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                        ) : (
                            <TableSkeleton />
                        )
                    ) : rows.length === 0 ? (
                        <EmptyState
                            icon={<ArrowLeftRight className="size-5" />}
                            title={t((d) => d.pages.walletTransactions.emptyTitle)}
                            description={t((d) => d.pages.walletTransactions.emptyHint)}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th className="w-8">
                                        <Checkbox
                                            checked={selection.allOnPage}
                                            indeterminate={selection.someOnPage}
                                            onCheckedChange={selection.toggleAll}
                                            aria-label={t((d) => d.common.selectPage)}
                                        />
                                    </th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.date)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.account)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.description)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.amount)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {paged.map((transaction) => (
                                <tr
                                    key={transaction.id}
                                    className={cn(selection.has(transaction.id) && 'row-selected')}
                                >
                                    <td className="col-hide w-8">
                                        <Checkbox
                                            checked={selection.has(transaction.id)}
                                            onCheckedChange={(next) => {
                                                selection.toggle(transaction.id, next);
                                            }}
                                            aria-label={transaction.description}
                                        />
                                    </td>
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
                                    <td data-label={t((d) => d.pages.walletTransactions.columns.status)}>
                                        {/* The answer itself, not merely that one exists: this list
                                            is the worklist, and "Chirii · DE" is what a person is
                                            checking for. An unanswered row stays a plain pill so it
                                            reads as the thing still to do. */}
                                        {transaction.splits.length === 0 ? (
                                            <Pill>
                                                {t((d) => d.pages.walletTransactions.classify.unclassified)}
                                            </Pill>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {transaction.splits.map((split, index) => (
                                                    <Pill key={index} tone="accent">
                                                        {t(
                                                            (d) =>
                                                                d.pages.walletTransactions.categories[
                                                                    split.categoryCode
                                                                ] ?? split.categoryCode,
                                                        )}
                                                        {split.countryCode === null ? '' : ` · ${split.countryCode}`}
                                                    </Pill>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td className="col-end text-right">
                                        <div className="flex justify-end gap-1.5">
                                        {/* One button, because there is one thing to change: the
                                            figures and what the money was are the same dialog. A
                                            separate "classify" opened a second dialog to edit one
                                            field the first was already showing. */}
                                        <PanelButton
                                            onClick={() => {
                                                errors.reset();
                                                setForm({
                                                    account_id: transaction.accountId,
                                                    amount: String(transaction.amountMinor / 100),
                                                    currency_code: transaction.currencyCode,
                                                    booked_at: transaction.bookedAt.slice(0, 10),
                                                    description: transaction.description,
                                                    counterparty: transaction.counterparty ?? '',
                                                    category_code:
                                                        transaction.splits[0]?.categoryCode ?? '',
                                                    country_code:
                                                        transaction.splits[0]?.countryCode ?? 'RO',
                                                });
                                                setEditing(transaction);
                                            }}
                                        >
                                            {t((d) => d.common.edit)}
                                        </PanelButton>
                                        <PanelButton
                                            variant="ghost"
                                            onClick={() => {
                                                setDeleting(transaction);
                                            }}
                                        >
                                            {t((d) => d.common.delete)}
                                        </PanelButton>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>

                {page?.capped === true ? (
                    <p className="note">
                        {t((d) => d.pages.walletTransactions.capped).replace('{limit}', String(page.limit))}
                    </p>
                ) : null}
            </Page>

            {confirmingBulkDelete ? (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setConfirmingBulkDelete(false);
                        }
                    }}
                    title={t((d) => d.pages.walletTransactions.deleteSelected)}
                    description={t((d) => d.pages.walletTransactions.deleteSelectedHint)}
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={deleteSelected}
                />
            ) : null}

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
                open={adding || editing !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        // Closed without saving: back to empty, so the next thing opened does not
                        // show what was typed into this one.
                        setAdding(false);
                        setEditing(null);
                        setForm(emptyTransactionForm());
                        errors.reset();
                    }
                }}
                title={
                    editing === null
                        ? t((d) => d.pages.walletTransactions.add)
                        : t((d) => d.common.edit)
                }
                description={t((d) => d.pages.walletTransactions.addHint)}
                size="lg"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setAdding(false);
                                setEditing(null);
                                setForm(emptyTransactionForm());
                                errors.reset();
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
                                    // The source's default when it has one. A broker or a crypto
                                    // source holds several currencies and carries none, so the
                                    // choice already made stands.
                                    currency_code: account?.currencyCode ?? current.currency_code,
                                }));
                            }}
                            options={accounts.map((account) => ({
                                value: account.id,
                                label:
                                    account.currencyCode === null
                                        ? sourceName(account, t((d) => d.pages.wallet.generalSource))
                                        : `${sourceName(account, t((d) => d.pages.wallet.generalSource))} (${account.currencyCode})`,
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
                        <PanelDate
                            block
                            disableFuture
                            value={form.booked_at === '' ? null : form.booked_at}
                            onChange={(next) => {
                                setForm((current) => ({ ...current, booked_at: next ?? '' }));
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
                            icon={<UserIcon className="size-3.5" />}
                            value={form.counterparty}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, counterparty: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.description)}
                        htmlFor="tx-description"
                        error={errors.get('description')}
                    >
                        <PanelInput
                            id="tx-description"
                            icon={<AlignLeftIcon className="size-3.5" />}
                            value={form.description}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, description: event.target.value }));
                            }}
                        />
                    </Field>

                    {/* Answered here rather than in a second dialog afterwards: someone typing a
                        transaction already knows what it was, and asking again later is work for
                        nothing. Optional — a row can be recorded now and answered from the
                        worklist, which is what imported and generated rows do. */}
                    <Field
                        label={t((d) => d.pages.walletTransactions.classify.category)}
                        error={errors.get('category_code')}
                    >
                        <PanelSelect
                            searchable
                            value={form.category_code}
                            placeholder={t((d) => d.pages.walletTransactions.classify.later)}
                            emptyLabel={t((d) => d.pages.walletTransactions.classify.later)}
                            onValueChange={(next) => {
                                setForm((current) => ({ ...current, category_code: next }));
                            }}
                            options={(page?.categories ?? []).map((category) => ({
                                value: category.code,
                                label: t(
                                    (d) => d.pages.walletTransactions.categories[category.code] ?? category.code,
                                ),
                            }))}
                        />
                    </Field>

                    {/* Only once a category has been chosen: the country qualifies the answer, and
                        asking where nothing came from is a field with no question behind it. */}
                    {form.category_code === '' ? null : (
                        <Field label={t((d) => d.pages.walletTransactions.classify.country)}>
                            <PanelSelect
                                searchable
                                value={form.country_code}
                                onValueChange={(next) => {
                                    setForm((current) => ({ ...current, country_code: next }));
                                }}
                                options={countries.map((country) => ({
                                    value: country.code,
                                    label: country.name,
                                }))}
                            />
                        </Field>
                    )}
                </FieldGrid>
            </PanelDialog>
        </AppShell>
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
