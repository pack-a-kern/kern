import {
    Banknote,
    Calculator,
    CalendarCheck,
    CalendarClock,
    Check,
    Coins,
    Hourglass,
    Landmark,
    LandmarkIcon,
    Pencil,
    Percent,
    PercentIcon,
    PlusIcon,
    SearchX,
    Shapes,
    TagIcon,
    Trash2,
    WalletCards,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteAccount, listAccounts, listBanks, openAccount, updateAccount } from '../../api/walletApi';
import AppShell from '../../components/AppShell';
import BulkActions from '../../components/BulkActions';
import Checkbox from '../../components/Checkbox';
import ConfirmDialog from '../../components/ConfirmDialog';
import Pagination from '../../components/Pagination';
import { bankOptions } from '../../components/wallet/bankOptions';
import { PanelDate } from '../../components/PanelDatePicker';
import {
    ActionGroup,
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
    Toolbar,
} from '../../components/panel';
import { DropdownMenuItem } from '../../components/ui/dropdown-menu';
import { Skeleton } from '../../components/ui/skeleton';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useRememberedPaging, useRememberedState } from '../../hooks/useRememberedState';
import { useRowSelection } from '../../hooks/useRowSelection';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { addMonths, daysBetween, maturityFor, termMonths as monthsOfTerm } from '../../lib/depositTerm';
import { formatDate } from '../../lib/format';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { cn } from '../../lib/utils';
import {
    OPENABLE_KINDS,
    isStreamKind,
    sourceName,
    CURRENCIES,
    INTEREST_METHODS,
    PAYMENT_FREQUENCIES,
    RATE_TYPES,
    type AccountSummary,
    type Bank,
    type DepositFacts,
    type DepositFormOptions,
    formatMinor,
} from '../../types/wallet';

/**
 * The taxpayer's deposits, cash and other accounts, in one table.
 *
 * **General is not listed.** It is where transactions land, created with the taxpayer and never
 * edited or deleted, so a row for it offered nothing to do. It is still where the funding select
 * points by default, and everywhere a transaction is recorded.
 *
 * **Every column says something about the row it is on.** Interest, maturity and status belong to a
 * deposit, so a cash holding or a broker account leaves them empty — the table used to put "Active"
 * on a pile of cash, where it meant nothing.
 *
 * The order comes from the server — running deposits by maturity, then settled ones, then cash, then
 * the others by name — because only the server can sort by a name that is encrypted at rest.
 */
/**
 * One form for every kind of account.
 *
 * The deposit half is always present in state and only *rendered* when the kind calls for it —
 * simpler than a discriminated union for a form whose fields are all strings anyway, and it means
 * switching kind twice does not lose what was already typed.
 */
interface AccountForm {
    display_name: string;
    kind: string;
    currency_code: string;
    /** A deposit's own start date — the day interest begins accruing. Deposits only. */
    starts_at: string;
    /** What a cash holding is worth, in major units. Cash only. */
    holding_amount: string;
    /**
     * Which source the deposit's money came out of. Deposits only, '' for "not one I track".
     *
     * Without it the principal is added to net worth while nothing leaves the account it came
     * from, and the same money is counted twice — see `OpenFinancialAccount`.
     */
    funding_account_id: string;

    /** The bank a deposit is at, chosen from the imported list. Deposits only — it names them. */
    bank_id: string;

    /* Term deposit only — see `AccountForm`. */
    principal: string;
    term_months: string;
    annual_rate: string;
    rate_type: string;
    interest_method: string;
    payment_frequency: string;
}

/**
 * The form pre-filled from an existing source.
 *
 * A deposit's term is derived back from its dates rather than stored: the form asks for a length in
 * months because that is how a deposit is bought, and the maturity is recomputed from it — so
 * round-tripping through months is what keeps editing consistent with opening.
 */
function formFor(account: AccountSummary): AccountForm {
    const deposit = account.deposit;

    return {
        ...emptyForm(),
        display_name: account.displayName,
        kind: account.kind,
        currency_code: account.currencyCode ?? deposit?.currencyCode ?? CURRENCIES[0],
        holding_amount:
            account.kind === 'CASH' && account.balances[0] !== undefined
                ? String(account.balances[0].amountMinor / 100)
                : '',
        ...(deposit === null
            ? {}
            : {
                  starts_at: deposit.startsAt,
                  funding_account_id: deposit.fundingAccountId ?? '',
                  bank_id: deposit.bankId ?? '',
                  principal: String(deposit.principalMinor / 100),
                  term_months: String(monthsOfTerm(deposit.startsAt, deposit.maturityAt)),
                  annual_rate: deposit.annualRate ?? '',
                  rate_type: deposit.rateType,
                  interest_method: deposit.interestMethod,
                  payment_frequency: deposit.paymentFrequency,
              }),
    };
}

function emptyForm(): AccountForm {
    return {
        display_name: '',
        // Defaults chosen so the commonest account is one field away: a RON current account.
        kind: OPENABLE_KINDS[0] ?? 'OTHER',
        currency_code: CURRENCIES[0],
        starts_at: '',
        holding_amount: '',
        funding_account_id: '',
        bank_id: '',
        principal: '',
        term_months: '',
        annual_rate: '',
        rate_type: RATE_TYPES[0],
        interest_method: INTEREST_METHODS[0],
        // At maturity: the commonest Romanian retail deposit pays once, at the end.
        payment_frequency: PAYMENT_FREQUENCIES[4],
    };
}

export default function WalletAccounts(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [loaded, setLoaded] = useState<{
        rows: AccountSummary[];
        options: DepositFormOptions;
    } | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    /** The account type shown, or '' for every type. */
    const [kindFilter, setKindFilter] = useRememberedState<string>('wallet.accounts.type', '', {
        perSubject: true,
        isValid: (value) => value === '' || (OPENABLE_KINDS as readonly string[]).includes(value),
    });
    const { page, pageSize, setPage, setPageSize } = useRememberedPaging('wallet.accounts', { perSubject: true });
    const [bulkDeleting, setBulkDeleting] = useState(false);
    const [confirmingBulkDelete, setConfirmingBulkDelete] = useState(false);

    const errors = useFormErrors();
    const [deleting, setDeleting] = useState<AccountSummary | null>(null);
    const [adding, setAdding] = useState(false);
    /** The source being edited, or null when the dialog is opening a new one. */
    const [editing, setEditing] = useState<AccountSummary | null>(null);
    const [saving, setSaving] = useState(false);
    // Typed explicitly: `OPENABLE_KINDS[0]` is a const-asserted tuple, so inference would pin
    // `kind` to the literal 'BANK_CURRENT' and reject every other value the select offers.
    const [form, setForm] = useState<AccountForm>(emptyForm());

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listAccounts(subjectId).then(
            (page) => {
                if (active) {
                    setLoaded({ rows: page.accounts, options: page.formOptions });
                    setFailed(false);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                    setLoaded({ rows: [], options: { termsMonths: [] } });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, reloadKey]);

    // The same list for every taxpayer, loaded once: it changes only when the bank import runs.
    const [banks, setBanks] = useState<Bank[]>([]);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listBanks(subjectId).then(
            (loadedBanks) => {
                if (active) {
                    setBanks(loadedBanks);
                }
            },
            () => {
                if (active) {
                    setBanks([]);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId]);

    const accounts = loaded?.rows ?? null;

    // Where a deposit's money comes from unless somebody says otherwise. The general source is the
    // one every taxpayer has and the one everything lands in by default, so it is the honest guess
    // — and a deposit funded from nothing at all is the case worth making a person choose.
    const generalAccountId = accounts?.find((account) => account.kind === 'GENERAL')?.id ?? '';

    // General is where transactions land, not something managed here — see the component docblock.
    const listed = useMemo(
        () =>
            (accounts ?? []).filter(
                (account) => account.kind !== 'GENERAL' && (kindFilter === '' || account.kind === kindFilter),
            ),
        [accounts, kindFilter],
    );

    /*
     * Paged in the browser: the endpoint returns every source for the subject — a handful, not
     * thousands — so asking the server for a page would be a round trip to slice an array already here.
     */
    const pageCount = Math.max(1, Math.ceil(listed.length / pageSize));
    const currentPage = Math.min(page, pageCount);
    const paged = listed.slice((currentPage - 1) * pageSize, currentPage * pageSize);
    const pageIds = useMemo(() => paged.map((account) => account.id), [paged]);
    const selection = useRowSelection(pageIds);

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.walletAccounts.title)}>
                    <Panel title={t((d) => d.pages.walletAccounts.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    // A term deposit is an account with extra terms, so the form grows rather than the app offering
    // a second place to add one. This is the whole reason `deposits` stopped being its own table.
    const options: DepositFormOptions = loaded?.options ?? { termsMonths: [] };
    const isDeposit = form.kind === 'TERM_DEPOSIT';
    // A cash source is one declared value rather than a container: the form asks for it, and
    // nothing else can ever add to it.
    const isCash = form.kind === 'CASH';
    // What an edit may still change, mirroring `UpdateFinancialAccount`. A settled deposit is a
    // record rather than a running product; once interest is on the ledger, the figures it was
    // computed from are part of that record too. Shown disabled, not left editable to fail on save.
    const depositClosed = editing?.deposit?.closed === true;
    const termsLocked = depositClosed || editing?.deposit?.interestPosted === true;
    const lockedHint = t((d) => d.pages.walletDeposits.form.lockedAfterInterest);

    // Derived on every render rather than stored: a second copy of the maturity could drift out of
    // step with the term that produced it, which is what asking for a term instead of a date fixes.
    const termMonths = Number(form.term_months);
    const hasTerm = form.starts_at !== '' && Number.isInteger(termMonths) && termMonths > 0;
    // A term ending on a weekend matures on the Monday after — see `maturityFor`.
    const maturityAt = hasTerm ? maturityFor(form.starts_at, termMonths) : '';
    const movedToWorkingDay = hasTerm && maturityAt !== addMonths(form.starts_at, termMonths);
    const termDays = maturityAt === '' ? null : daysBetween(form.starts_at, maturityAt);

    /** Labels a term the way it is sold — "6 luni", and the singular for one month. */
    const termLabel = (months: number): string =>
        months === 1
            ? t((d) => d.pages.walletDeposits.form.termOneMonth)
            : t((d) => d.pages.walletDeposits.form.termMonths).replace('{months}', String(months));

    // Neither handler re-checks `subjectId`: the early return above this point already narrowed it
    // to a string, and TypeScript rejects the redundant check as unreachable.

    const deleteSelected = async (): Promise<void> => {
        setBulkDeleting(true);

        let removed = 0;

        try {
            // One request each: there is no bulk endpoint, and inventing one would skip the audit
            // event every single delete already writes. Sequential, so a refusal stops the run with a
            // coherent list rather than silently skipping to the next source.
            for (const id of selection.ids) {
                await deleteAccount(subjectId, id);
                removed += 1;
            }

            toast.success(t((d) => d.pages.walletAccounts.deleted));
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.common.deleteFailed),
                { description: describeApiFailure(cause, t) },
            );
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

        const body = {
            // A deposit is named after its bank, so it sends the bank and never a name.
            ...(isDeposit ? { bank_id: form.bank_id } : { display_name: form.display_name }),
            currency_code: form.currency_code,
            ...(form.starts_at === '' ? {} : { starts_at: form.starts_at }),
            ...(isCash ? { holding_amount_minor: Math.round(Number(form.holding_amount) * 100) } : {}),
            // A term deposit is an account with extra terms, sent in the same request. The
            // server refuses these for any other kind, so they are only attached when the kind
            // actually calls for them.
            ...(isDeposit
                ? {
                      principal_minor: Math.round(Number(form.principal) * 100),
                      maturity_at: maturityAt,
                      // '' is a real answer — a deposit opened out of an account nobody tracked —
                      // and it has to reach the server as null rather than be omitted, or editing
                      // could never clear a funding account once set.
                      funding_account_id: form.funding_account_id === '' ? null : form.funding_account_id,
                      annual_rate: form.annual_rate,
                      rate_type: form.rate_type,
                      interest_method: form.interest_method,
                      payment_frequency: form.payment_frequency,
                  }
                : {}),
        };

        try {
            if (editing === null) {
                await openAccount(subjectId, { ...body, kind: form.kind });
            } else {
                // `kind` is deliberately absent: the server rejects it outright rather than
                // ignoring it, so a caller cannot believe a change took effect that did not.
                await updateAccount(subjectId, editing.id, body);
            }

            toast.success(
                editing === null ? t((d) => d.pages.walletAccounts.added) : t((d) => d.pages.walletAccounts.saved),
            );
            setAdding(false);
            setEditing(null);
            setForm(emptyForm());
            // Re-fetch rather than pushing the new row in locally: the server owns the id, and the
            // balance column comes from a snapshot that does not exist yet.
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            // A 422 lands on the fields themselves; anything else is a toast.
            if (!errors.capture(cause)) {
                toast.error(t((d) => d.pages.walletAccounts.addFailed));
            }
        } finally {
            setSaving(false);
        }
    };

    const bankCodes = new Map(banks.map((bank) => [bank.id, bank.code]));

    const openAdd = (): void => {
        errors.reset();
        // From `emptyForm()`, not from whatever was last typed: the dialog is shared with editing, so
        // carrying state over showed one source's details under "Add". The terms arrive with the
        // account list, so by the time this can be pressed they are known.
        setForm({
            ...emptyForm(),
            term_months: String(options.termsMonths[0] ?? ''),
            funding_account_id: generalAccountId,
        });
        setAdding(true);
    };

    /** Edit and delete, in the one control every row in this application draws them as. */
    const rowActions = (account: AccountSummary): JSX.Element => (
        <div className="flex justify-end">
            <ActionGroup
                actions={[
                    {
                        key: 'edit',
                        icon: <Pencil className="size-3.5" />,
                        label: t((d) => d.pages.walletAccounts.edit),
                        onClick: () => {
                            errors.reset();
                            setForm(formFor(account));
                            setEditing(account);
                        },
                    },
                    {
                        key: 'delete',
                        icon: <Trash2 className="size-3.5" />,
                        label: t((d) => d.common.delete),
                        tone: 'destructive' as const,
                        onClick: () => {
                            setDeleting(account);
                        },
                    },
                ]}
            />
        </div>
    );

    /**
     * Running, matured, or closed. Matured-but-open lasts until the nightly settlement returns the
     * money, which is why it is its own state rather than folded into either neighbour.
     */
    const depositStatus = (deposit: DepositFacts): JSX.Element =>
        deposit.closed ? (
            <Status tone="idle">{t((d) => d.pages.walletDeposits.closed)}</Status>
        ) : deposit.daysToMaturity < 0 ? (
            <Status tone="warn">{t((d) => d.pages.walletDeposits.matured)}</Status>
        ) : (
            <Status tone="ok">{t((d) => d.pages.walletDeposits.running)}</Status>
        );

    return (
        <AppShell>
            <Page title={t((d) => d.pages.walletAccounts.title)} lede={t((d) => d.pages.walletAccounts.description)}>
                <Panel
                    title={t((d) => d.pages.walletAccounts.title)}
                    description={t((d) => d.pages.walletAccounts.description)}
                    icon={<LandmarkIcon className="size-4" />}
                    flush
                    aside={
                        <PanelButton primary onClick={openAdd}>
                            <PlusIcon className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.walletAccounts.add)}
                        </PanelButton>
                    }
                    foot={
                        <Pagination
                            page={currentPage}
                            pageSize={pageSize}
                            total={listed.length}
                            unit={t((d) => d.pages.walletAccounts.unit)}
                            onPageChange={setPage}
                            onPageSizeChange={(size) => {
                                setPageSize(size);
                                setPage(1);
                            }}
                        />
                    }
                >
                    {/* Only once something is ticked — see `BulkActions`. Each source is judged on its
                        own: one that still has transactions is refused rather than cascaded. */}
                    <Toolbar>
                        <PanelSelect
                            icon={<Shapes className="size-3.5" aria-hidden="true" />}
                            className="filter-select"
                            value={kindFilter}
                            onValueChange={(value) => {
                                setKindFilter(value);
                                setPage(1);
                            }}
                            placeholder={t((d) => d.pages.walletAccounts.allTypes)}
                            emptyLabel={t((d) => d.pages.walletAccounts.allTypes)}
                            options={OPENABLE_KINDS.map((kind) => ({
                                value: kind,
                                label: t((d) => d.pages.wallet.kinds[kind] ?? kind),
                            }))}
                        />
                        <BulkActions count={selection.size} disabled={bulkDeleting}>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => {
                                    setConfirmingBulkDelete(true);
                                }}
                            >
                                <Trash2 className="size-4" />
                                {t((d) => d.common.deleteSelected)}
                            </DropdownMenuItem>
                        </BulkActions>
                    </Toolbar>

                    {accounts === null ? (
                        <TableSkeleton />
                    ) : failed ? (
                        <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                    ) : listed.length === 0 && kindFilter !== '' ? (
                        <EmptyState
                            icon={<SearchX className="size-5" />}
                            title={t((d) => d.pages.walletAccounts.noMatches)}
                        />
                    ) : listed.length === 0 ? (
                        <EmptyState
                            icon={<LandmarkIcon className="size-5" />}
                            title={t((d) => d.pages.walletAccounts.emptyTitle)}
                            description={t((d) => d.pages.walletAccounts.emptyHint)}
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
                                    <th>{t((d) => d.pages.walletAccounts.columns.account)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.amount)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.interest)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.maturity)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {paged.map((account) => {
                                const deposit = account.deposit;
                                const code =
                                    deposit === null || deposit.bankId === null
                                        ? null
                                        : (bankCodes.get(deposit.bankId) ?? null);

                                return (
                                    <tr key={account.id} className={cn(selection.has(account.id) && 'row-selected')}>
                                        <td className="col-hide w-8">
                                            <Checkbox
                                                checked={selection.has(account.id)}
                                                onCheckedChange={(next) => {
                                                    selection.toggle(account.id, next);
                                                }}
                                                aria-label={account.displayName}
                                            />
                                        </td>
                                        <td className="col-primary">
                                            <div className="flex items-center gap-2 font-semibold">
                                                {account.displayName}
                                                {code === null ? null : <Pill>{code}</Pill>}
                                            </div>
                                            <div className="note">
                                                {t((d) => d.pages.wallet.kinds[account.kind] ?? account.kind)}
                                                {deposit === null
                                                    ? ''
                                                    : ` · ${t((d) => d.pages.walletDeposits.startedOn).replace(
                                                          '{date}',
                                                          formatDate(deposit.startsAt, locale),
                                                      )}`}
                                            </div>
                                        </td>
                                        <td
                                            className="tabular-nums whitespace-nowrap"
                                            data-label={t((d) => d.pages.walletAccounts.columns.amount)}
                                        >
                                            {/* A deposit's money is its principal; anything else is what
                                                has been recorded in it, one line per currency. */}
                                            {deposit !== null ? (
                                                formatMinor(deposit.principalMinor, deposit.currencyCode, locale)
                                            ) : account.balances.length === 0 ? (
                                                <span className="note">{t((d) => d.pages.wallet.unknownBalance)}</span>
                                            ) : (
                                                account.balances.map((balance) => (
                                                    <div key={balance.currencyCode}>
                                                        {formatMinor(balance.amountMinor, balance.currencyCode, locale)}
                                                    </div>
                                                ))
                                            )}
                                        </td>
                                        <td
                                            className="tabular-nums whitespace-nowrap"
                                            data-label={t((d) => d.pages.walletAccounts.columns.interest)}
                                        >
                                            {deposit === null ? null : (
                                                /* One element: a stacked row lays a labelled cell out as a
                                                   flex row, so loose siblings become columns beside the
                                                   label rather than lines under each other. */
                                                <div className="min-w-0 text-right sm:text-left">
                                                    {deposit.interestAtMaturityMinor === null ? (
                                                        <span className="note">
                                                            {t((d) => d.pages.walletDeposits.notComputed)}
                                                        </span>
                                                    ) : (
                                                        `+ ${formatMinor(deposit.interestAtMaturityMinor, deposit.currencyCode, locale)}`
                                                    )}
                                                    <div className="note">
                                                        {deposit.annualRate === null
                                                            ? t((d) => d.pages.walletDeposits.noRate)
                                                            : `${deposit.annualRate}%`}
                                                    </div>
                                                    {deposit.interestReceivedMinor > 0 ? (
                                                        <div className="note">
                                                            {t((d) => d.pages.walletDeposits.receivedSoFar).replace(
                                                                '{amount}',
                                                                formatMinor(
                                                                    deposit.interestReceivedMinor,
                                                                    deposit.currencyCode,
                                                                    locale,
                                                                ),
                                                            )}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            )}
                                        </td>
                                        <td
                                            className="whitespace-nowrap"
                                            data-label={t((d) => d.pages.walletAccounts.columns.maturity)}
                                        >
                                            {deposit === null ? null : (
                                                <>
                                                    {formatDate(deposit.maturityAt, locale)}
                                                    <div className="note">
                                                        {deposit.closed || deposit.daysToMaturity < 0
                                                            ? t((d) => d.pages.walletDeposits.totalDays).replace(
                                                                  '{days}',
                                                                  String(deposit.totalDays),
                                                              )
                                                            : t((d) => d.pages.walletDeposits.daysLeft).replace(
                                                                  '{days}',
                                                                  String(deposit.daysToMaturity),
                                                              )}
                                                    </div>
                                                </>
                                            )}
                                        </td>
                                        <td data-label={t((d) => d.pages.walletAccounts.columns.status)}>
                                            {deposit === null ? null : depositStatus(deposit)}
                                        </td>
                                        <td className="col-actions text-right">{rowActions(account)}</td>
                                    </tr>
                                );
                            })}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {confirmingBulkDelete ? (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setConfirmingBulkDelete(false);
                        }
                    }}
                    title={t((d) => d.common.deleteSelected)}
                    description={t((d) => d.pages.walletAccounts.deleteSelectedHint)}
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
                    title={t((d) => d.pages.walletAccounts.deleteTitle)}
                    description={t((d) => d.pages.walletAccounts.deleteHint)}
                    detail={<p className="note">{deleting.displayName}</p>}
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await deleteAccount(subjectId, deleting.id);
                            toast.success(t((d) => d.pages.walletAccounts.deleted));
                            setDeleting(null);
                            setReloadKey((current) => current + 1);
                        } catch (cause: unknown) {
                            // The server states *why* it refused — transactions still point at this
                            // source, or it is the general one and cannot be removed at all. Both
                            // are answers a person can act on, so the standard describer relays
                            // them rather than a generic "deletion failed".
                            toast.error(
                                t((d) => d.common.deleteFailed),
                                {
                                    description: describeApiFailure(cause, t),
                                },
                            );

                            throw cause;
                        }
                    }}
                />
            )}

            {/* One dialog for both, because they are the same form: the only difference is whether
                the source already exists. `kind` is fixed once it does — it decides whether
                transactions may be recorded against the source, so changing it would reinterpret
                what is already stored. */}
            <PanelDialog
                open={adding || editing !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        // Closed without saving: the form goes back to empty, so the next thing
                        // opened — an "Add", or a different source — does not show what was typed
                        // into this one.
                        setAdding(false);
                        setEditing(null);
                        setForm(emptyForm());
                        errors.reset();
                    }
                }}
                title={editing === null ? t((d) => d.pages.walletAccounts.add) : t((d) => d.pages.walletAccounts.edit)}
                description={t((d) => d.pages.walletAccounts.addHint)}
                size="lg"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setAdding(false);
                                setEditing(null);
                                setForm(emptyForm());
                                errors.reset();
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.common.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={
                                saving ||
                                depositClosed ||
                                (isDeposit ? form.bank_id === '' : form.display_name.trim() === '') ||
                                (isCash && form.holding_amount.trim() === '') ||
                                (isDeposit &&
                                    (form.principal.trim() === '' ||
                                        form.annual_rate.trim() === '' ||
                                        maturityAt === ''))
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
                    {depositClosed ? (
                        <p className="note sm:col-span-2">{t((d) => d.pages.walletDeposits.form.closedNotice)}</p>
                    ) : null}

                    {/* A deposit is at a bank, and that bank is its name: chosen from the list the bank import
                        keeps, never typed, so two deposits at one bank cannot be spelled two ways. */}
                    {isDeposit ? (
                        <Field
                            label={t((d) => d.pages.walletDeposits.form.bank)}
                            htmlFor="account-bank"
                            error={errors.get('bank_id')}
                            hint={
                                banks.length === 0
                                    ? t((d) => d.pages.walletDeposits.form.bankEmpty)
                                    : t((d) => d.pages.walletDeposits.form.bankHint)
                            }
                        >
                            <PanelSelect
                                icon={<Landmark className="size-3.5" aria-hidden="true" />}
                                id="account-bank"
                                disabled={depositClosed}
                                value={form.bank_id}
                                placeholder={t((d) => d.pages.walletDeposits.form.bankPlaceholder)}
                                // Sixty-odd banks, and more once another country is configured. The
                                // label carries the 4-letter code, so searching "BTRL" finds it too.
                                searchable
                                onValueChange={(value) => {
                                    setForm((current) => ({ ...current, bank_id: value }));
                                }}
                                optionVisual="bare"
                                // This list is the current banks only, so every dot is green — drawn
                                // anyway so the column reads the same here as everywhere else.
                                options={bankOptions(
                                    banks,
                                    t((d) => d.pages.walletTransactions.bank.closed),
                                )}
                            />
                        </Field>
                    ) : (
                        <Field
                            label={t((d) => d.pages.walletAccounts.form.displayName)}
                            htmlFor="account-display-name"
                            error={errors.get('display_name')}
                            hint={t((d) => d.pages.walletAccounts.form.displayNameHint)}
                        >
                            <PanelInput
                                id="account-display-name"
                                icon={<TagIcon className="size-3.5" />}
                                value={form.display_name}
                                onChange={(event) => {
                                    setForm((current) => ({ ...current, display_name: event.target.value }));
                                }}
                            />
                        </Field>
                    )}

                    <Field
                        label={t((d) => d.pages.walletAccounts.form.kind)}
                        htmlFor="account-kind"
                        error={errors.get('kind')}
                    >
                        {/* Fixed once the source exists: the server refuses `kind` on an edit, because
                            it decides whether transactions may be recorded against the source at all. */}
                        <PanelSelect
                            icon={<Shapes className="size-3.5" aria-hidden="true" />}
                            id="account-kind"
                            disabled={editing !== null}
                            value={form.kind}
                            onValueChange={(value) => {
                                setForm((current) => ({ ...current, kind: value }));
                            }}
                            options={OPENABLE_KINDS.map((kind) => ({
                                value: kind,
                                label: t((d) => d.pages.wallet.kinds[kind] ?? kind),
                            }))}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletAccounts.form.currency)}
                        htmlFor="account-currency"
                        error={errors.get('currency_code')}
                        {...(isDeposit && termsLocked ? { hint: lockedHint } : {})}
                    >
                        <PanelSelect
                            icon={<Coins className="size-3.5" aria-hidden="true" />}
                            id="account-currency"
                            disabled={isDeposit && termsLocked}
                            value={form.currency_code}
                            onValueChange={(value) => {
                                setForm((current) => ({ ...current, currency_code: value }));
                            }}
                            options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                        />
                    </Field>

                    {isCash ? (
                        <Field
                            label={t((d) => d.pages.walletAccounts.form.holdingAmount)}
                            htmlFor="account-holding"
                            error={errors.get('holding_amount_minor')}
                            hint={t((d) => d.pages.walletAccounts.form.holdingAmountHint)}
                        >
                            <PanelInput
                                id="account-holding"
                                icon={<Banknote className="size-3.5" />}
                                inputMode="decimal"
                                value={form.holding_amount}
                                onChange={(event) => {
                                    setForm((current) => ({ ...current, holding_amount: event.target.value }));
                                }}
                            />
                        </Field>
                    ) : null}

                    {/* A deposit's own start date, and only a deposit's: it is the day interest
                        begins accruing and the left edge of the span its rate periods must cover,
                        not metadata about a folder. */}
                    {isDeposit ? (
                        <Field
                            label={t((d) => d.pages.walletAccounts.form.startsAt)}
                            htmlFor="account-starts"
                            error={errors.get('starts_at')}
                            {...(termsLocked ? { hint: lockedHint } : {})}
                        >
                            <PanelDate
                                block
                                disabled={termsLocked}
                                value={form.starts_at === '' ? null : form.starts_at}
                                onChange={(next) => {
                                    setForm((current) => ({ ...current, starts_at: next ?? '' }));
                                }}
                            />
                        </Field>
                    ) : null}

                    {/* The deposit half. It appears when the kind calls for it rather than living on
                        a second screen — a term deposit is an account, and offering two places to
                        add one is what made the two look like different things. */}
                    {isDeposit ? (
                        <>
                            <Field
                                label={t((d) => d.pages.walletDeposits.form.fundingAccount)}
                                htmlFor="account-funding"
                                error={errors.get('funding_account_id')}
                                hint={
                                    termsLocked ? lockedHint : t((d) => d.pages.walletDeposits.form.fundingAccountHint)
                                }
                            >
                                <PanelSelect
                                    icon={<WalletCards className="size-3.5" aria-hidden="true" />}
                                    id="account-funding"
                                    disabled={termsLocked}
                                    value={form.funding_account_id}
                                    placeholder={t((d) => d.pages.walletDeposits.form.fundingAccountNone)}
                                    onValueChange={(value) => {
                                        setForm((current) => ({ ...current, funding_account_id: value }));
                                    }}
                                    options={(accounts ?? [])
                                        .filter((candidate) => isStreamKind(candidate.kind))
                                        .map((candidate) => ({
                                            value: candidate.id,
                                            label: sourceName(
                                                candidate,
                                                t((d) => d.pages.wallet.generalSource),
                                            ),
                                        }))}
                                />
                            </Field>

                            <Field
                                label={t((d) => d.pages.walletDeposits.form.principal)}
                                htmlFor="account-principal"
                                error={errors.get('principal_minor')}
                                hint={termsLocked ? lockedHint : t((d) => d.pages.walletDeposits.form.principalHint)}
                            >
                                <PanelInput
                                    icon={<Coins className="size-3.5" aria-hidden="true" />}
                                    id="account-principal"
                                    disabled={termsLocked}
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={form.principal}
                                    onChange={(event) => {
                                        setForm((current) => ({ ...current, principal: event.target.value }));
                                    }}
                                />
                            </Field>

                            <Field
                                label={t((d) => d.pages.walletDeposits.form.annualRate)}
                                htmlFor="account-rate"
                                error={errors.get('annual_rate')}
                                hint={t((d) => d.pages.walletDeposits.form.annualRateHint)}
                            >
                                <PanelInput
                                    id="account-rate"
                                    disabled={depositClosed}
                                    icon={<PercentIcon className="size-3.5" />}
                                    inputMode="decimal"
                                    value={form.annual_rate}
                                    onChange={(event) => {
                                        setForm((current) => ({ ...current, annual_rate: event.target.value }));
                                    }}
                                />
                            </Field>

                            <Field
                                label={t((d) => d.pages.walletDeposits.form.term)}
                                htmlFor="account-term"
                                hint={t((d) => d.pages.walletDeposits.form.termHint)}
                            >
                                <PanelSelect
                                    icon={<Hourglass className="size-3.5" aria-hidden="true" />}
                                    id="account-term"
                                    disabled={depositClosed}
                                    value={form.term_months}
                                    onValueChange={(value) => {
                                        setForm((current) => ({ ...current, term_months: value }));
                                    }}
                                    options={options.termsMonths.map((months) => ({
                                        value: String(months),
                                        label: termLabel(months),
                                    }))}
                                />
                            </Field>

                            {/* Derived, not entered — but shown, because the interest is counted
                                between the opening date and this one. */}
                            <Field
                                label={t((d) => d.pages.walletDeposits.form.maturityAt)}
                                htmlFor="account-maturity"
                                error={errors.get('maturity_at')}
                                hint={
                                    termDays === null
                                        ? t((d) => d.pages.walletDeposits.form.maturityPending)
                                        : [
                                              t((d) => d.pages.walletDeposits.form.maturityCalculated).replace(
                                                  '{days}',
                                                  String(termDays),
                                              ),
                                              ...(movedToWorkingDay
                                                  ? [t((d) => d.pages.walletDeposits.form.maturityMoved)]
                                                  : []),
                                          ].join(' ')
                                }
                            >
                                <PanelInput
                                    id="account-maturity"
                                    type="date"
                                    value={maturityAt}
                                    disabled
                                    readOnly
                                    icon={<CalendarCheck className="size-3.5" aria-hidden="true" />}
                                />
                            </Field>

                            <Field
                                label={t((d) => d.pages.walletDeposits.form.rateType)}
                                htmlFor="account-rate-type"
                                error={errors.get('rate_type')}
                            >
                                <PanelSelect
                                    icon={<Percent className="size-3.5" aria-hidden="true" />}
                                    id="account-rate-type"
                                    disabled={termsLocked}
                                    value={form.rate_type}
                                    onValueChange={(value) => {
                                        setForm((current) => ({ ...current, rate_type: value }));
                                    }}
                                    options={RATE_TYPES.map((value) => ({
                                        value,
                                        label: t((d) => d.pages.wallet.rateTypes[value] ?? value),
                                    }))}
                                />
                            </Field>

                            <Field
                                label={t((d) => d.pages.walletDeposits.form.interestMethod)}
                                htmlFor="account-method"
                                error={errors.get('interest_method')}
                            >
                                <PanelSelect
                                    icon={<Calculator className="size-3.5" aria-hidden="true" />}
                                    id="account-method"
                                    disabled={termsLocked}
                                    value={form.interest_method}
                                    onValueChange={(value) => {
                                        setForm((current) => ({ ...current, interest_method: value }));
                                    }}
                                    options={INTEREST_METHODS.map((value) => ({
                                        value,
                                        label: t((d) => d.pages.wallet.interestMethods[value] ?? value),
                                    }))}
                                />
                            </Field>

                            <Field
                                label={t((d) => d.pages.walletDeposits.form.paymentFrequency)}
                                htmlFor="account-frequency"
                                error={errors.get('payment_frequency')}
                            >
                                <PanelSelect
                                    icon={<CalendarClock className="size-3.5" aria-hidden="true" />}
                                    id="account-frequency"
                                    disabled={termsLocked}
                                    value={form.payment_frequency}
                                    onValueChange={(value) => {
                                        setForm((current) => ({ ...current, payment_frequency: value }));
                                    }}
                                    options={PAYMENT_FREQUENCIES.map((value) => ({
                                        value,
                                        label: t((d) => d.pages.wallet.frequencies[value] ?? value),
                                    }))}
                                />
                            </Field>
                        </>
                    ) : null}
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
