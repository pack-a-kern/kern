import { useEffect, useMemo, useState, type JSX } from 'react';

import { deleteAccount, listAccounts, openAccount, updateAccount } from '../../api/walletApi';
import { Banknote, LandmarkIcon, PercentIcon, PlusIcon, TagIcon, Trash2 } from 'lucide-react';
import AppShell from '../../components/AppShell';
import { PanelDate } from '../../components/PanelDatePicker';
import ConfirmDialog from '../../components/ConfirmDialog';
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
import { Skeleton } from '../../components/ui/skeleton';
import { useFormErrors } from '../../hooks/useFormErrors';
import BulkActions from '../../components/BulkActions';
import Checkbox from '../../components/Checkbox';
import Pagination, { PAGE_SIZES } from '../../components/Pagination';
import { DropdownMenuItem } from '../../components/ui/dropdown-menu';
import { useRowSelection } from '../../hooks/useRowSelection';
import { useTranslation } from '../../i18n';
import { cn } from '../../lib/utils';
import { addMonths, daysBetween } from '../../lib/depositTerm';
import { describeApiFailure } from '../../lib/apiErrors';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { toast } from 'sonner';

import {
    OPENABLE_KINDS,
    sourceName,
    CURRENCIES,
    INTEREST_METHODS,
    PAYMENT_FREQUENCIES,
    RATE_TYPES,
    type AccountSummary,
    type DepositFormOptions,
    formatMinor,
} from '../../types/wallet';

/**
 * Bank accounts for the active tax subject.
 *
 * Two columns carry more meaning than they look like they do:
 *
 * **Balance** is the most recent *observation*, not a live figure — an account has no balance of its
 * own in this schema, only a history of snapshots. So the date it was observed is shown beside it;
 * a balance without its age is a number nobody can act on. An account with no snapshot yet shows
 * "unknown", never zero.
 *
 * **Source** says where that observation came from — typed by hand, imported, or read from SPV —
 * because a manually entered balance and a bank-confirmed one are not equally trustworthy, and the
 * person reconciling them needs to see which is which.
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

    /* Term deposit only — see `AccountForm`. */
    principal: string;
    term_months: string;
    annual_rate: string;
    rate_type: string;
    interest_method: string;
    payment_frequency: string;
    auto_renewal: boolean;
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
                  principal: String(deposit.principalMinor / 100),
                  term_months: String(monthsBetween(deposit.startsAt, deposit.maturityAt)),
                  annual_rate: deposit.annualRate ?? '',
                  rate_type: deposit.rateType,
                  interest_method: deposit.interestMethod,
                  payment_frequency: deposit.paymentFrequency,
                  auto_renewal: deposit.autoRenewal,
              }),
    };
}

/** Whole months between two ISO dates, which is the unit the deposit form works in. */
function monthsBetween(from: string, to: string): number {
    const start = new Date(from);
    const end = new Date(to);

    return Math.max(1, (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth()));
}

function emptyForm(): AccountForm {
    return {
        display_name: '',
        // Defaults chosen so the commonest account is one field away: a RON current account.
        kind: OPENABLE_KINDS[0] ?? 'OTHER',
        currency_code: CURRENCIES[0],
        starts_at: '',
        holding_amount: '',
        principal: '',
        term_months: '',
        annual_rate: '',
        rate_type: RATE_TYPES[0],
        interest_method: INTEREST_METHODS[0],
        // At maturity: the commonest Romanian retail deposit pays once, at the end.
        payment_frequency: PAYMENT_FREQUENCIES[4],
        auto_renewal: false,
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
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState<number>(PAGE_SIZES[1]);
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

    const accounts = loaded?.rows ?? null;

    /*
     * Paged in the browser, over the whole list.
     *
     * The endpoint returns every source for the subject — there are a handful, not thousands — so
     * asking the server for a page would be a round trip to slice an array that is already here.
     * The pager still exists because a broker, three banks and two deposits is enough to want one.
     */
    const pageCount = Math.max(1, Math.ceil((accounts?.length ?? 0) / pageSize));
    const currentPage = Math.min(page, pageCount);
    const paged = (accounts ?? []).slice((currentPage - 1) * pageSize, currentPage * pageSize);
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

    // Derived on every render rather than stored: a second copy of the maturity could drift out of
    // step with the term that produced it, which is what asking for a term instead of a date fixes.
    const termMonths = Number(form.term_months);
    const maturityAt =
        form.starts_at !== '' && Number.isInteger(termMonths) && termMonths > 0
            ? addMonths(form.starts_at, termMonths)
            : '';
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
            // event every single delete already writes. Sequential rather than parallel so a
            // partial failure leaves a coherent list — and each source is judged on its own, so a
            // refusal stops the run rather than silently skipping to the next.
            for (const id of selection.ids) {
                await deleteAccount(subjectId, id);
                removed += 1;
            }

            toast.success(t((d) => d.pages.walletAccounts.deleted));
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

        const body = {
                display_name: form.display_name,
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
                          annual_rate: form.annual_rate,
                          rate_type: form.rate_type,
                          interest_method: form.interest_method,
                          payment_frequency: form.payment_frequency,
                          auto_renewal: form.auto_renewal,
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
                editing === null
                    ? t((d) => d.pages.walletAccounts.added)
                    : t((d) => d.pages.walletAccounts.saved),
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

    return (
        <AppShell>
            <Page title={t((d) => d.pages.walletAccounts.title)} lede={t((d) => d.pages.walletAccounts.description)}>
                <Panel
                    title={t((d) => d.pages.walletAccounts.title)}
                    description={t((d) => d.pages.walletAccounts.description)}
                    icon={<LandmarkIcon className="size-4" />}
                    flush
                    aside={
                        <PanelButton
                            primary
                            inline
                            onClick={() => {
                                errors.reset();
                                // From `emptyForm()`, not from whatever was last typed: the dialog
                                // is shared with editing, so carrying state over showed one
                                // source's details under "Add". Defaults are set here rather than
                                // in an effect because the terms arrive with the account list, so
                                // by the time this can be pressed they are known.
                                setForm({ ...emptyForm(), term_months: String(options.termsMonths[0] ?? '') });
                                setAdding(true);
                            }}
                        >
                            <PlusIcon className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.walletAccounts.add)}
                        </PanelButton>
                    }
                    foot={
                        <Pagination
                            page={currentPage}
                            pageSize={pageSize}
                            total={accounts?.length ?? 0}
                            unit={t((d) => d.pages.walletAccounts.unit)}
                            onPageChange={setPage}
                            onPageSizeChange={(size) => {
                                setPageSize(size);
                                setPage(1);
                            }}
                        />
                    }
                >
                    {/* Only once something is ticked — see `BulkActions`. Deleting is the one thing
                        several sources can sensibly have done to them at once, and the same refusals
                        apply to each: the general source is never removed, and one holding
                        transactions is refused rather than cascaded. */}
                    <Toolbar>
                        <BulkActions count={selection.size} disabled={bulkDeleting}>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => {
                                    setConfirmingBulkDelete(true);
                                }}
                            >
                                <Trash2 className="size-4" />
                                {t((d) => d.pages.walletAccounts.deleteSelected)}
                            </DropdownMenuItem>
                        </BulkActions>
                    </Toolbar>

                    {accounts === null ? (
                        <TableSkeleton />
                    ) : failed ? (
                        <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                    ) : accounts.length === 0 ? (
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
                                    <th>{t((d) => d.pages.walletAccounts.columns.type)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.balance)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {paged.map((account) => (
                                <tr key={account.id} className={cn(selection.has(account.id) && 'row-selected')}>
                                    {/* The general source is never removed, so it is never ticked:
                                        a checkbox that cannot lead to an action is a control that
                                        lies about what is possible. */}
                                    <td className="col-hide w-8">
                                        {account.kind === 'GENERAL' ? null : (
                                            <Checkbox
                                                checked={selection.has(account.id)}
                                                onCheckedChange={(next) => {
                                                    selection.toggle(account.id, next);
                                                }}
                                                aria-label={account.displayName}
                                            />
                                        )}
                                    </td>
                                    <td className="col-primary">
                                        <div className="font-semibold">
                                            {sourceName(account, t((d) => d.pages.wallet.generalSource))}
                                        </div>
                                    </td>
                                    <td className="col-hide">
                                        <Pill>{t((d) => d.pages.wallet.kinds[account.kind] ?? account.kind)}</Pill>
                                    </td>
                                    <td className="tabular-nums whitespace-nowrap">
                                        {/* A deposit's money is its principal, not a running total:
                                            nothing moves through a locked account, so summing
                                            transactions there would report zero. The interest it
                                            will have earned by maturity goes underneath, because
                                            that is the number a deposit is opened to find out. */}
                                        {account.deposit !== null ? (
                                            <>
                                                {formatMinor(
                                                    account.deposit.principalMinor,
                                                    account.deposit.currencyCode,
                                                    locale,
                                                )}
                                                <div className="note">
                                                    {account.deposit.interestAtMaturityMinor === null
                                                        ? t((d) => d.pages.walletDeposits.notComputed)
                                                        : `+ ${formatMinor(
                                                              account.deposit.interestAtMaturityMinor,
                                                              account.deposit.currencyCode,
                                                              locale,
                                                          )}`}
                                                    {account.deposit.annualRate === null
                                                        ? ''
                                                        : ` · ${account.deposit.annualRate}%`}
                                                </div>
                                            </>
                                        ) : account.balances.length === 0 ? (
                                            <span className="note">{t((d) => d.pages.wallet.unknownBalance)}</span>
                                        ) : (
                                            /* One line per currency. A source is a folder and may
                                               hold several; adding them together would produce a
                                               number that is not any quantity of anything. */
                                            account.balances.map((balance) => (
                                                <div key={balance.currencyCode}>
                                                    {formatMinor(balance.amountMinor, balance.currencyCode, locale)}
                                                </div>
                                            ))
                                        )}
                                        <div className="note">{t((d) => d.pages.wallet.derived)}</div>
                                    </td>
                                    <td>
                                        {account.deposit === null ? (
                                            <Status tone="ok">{t((d) => d.pages.walletAccounts.active)}</Status>
                                        ) : (
                                            <Status tone={account.deposit.daysToMaturity < 0 ? 'idle' : 'ok'}>
                                                {account.deposit.daysToMaturity < 0
                                                    ? t((d) => d.pages.walletDeposits.matured)
                                                    : t((d) => d.pages.walletDeposits.daysLeft).replace(
                                                          '{days}',
                                                          String(account.deposit.daysToMaturity),
                                                      )}
                                            </Status>
                                        )}
                                    </td>
                                    <td className="col-end text-right">
                                        {/* One control holding both actions, the way every other
                                            row in this application draws them — a tile with a
                                            divider, not two loose buttons. */}
                                        {account.kind === 'GENERAL' ? (
                                            <span className="note">
                                                {t((d) => d.pages.walletAccounts.systemSource)}
                                            </span>
                                        ) : (
                                            <div className="flex justify-end">
                                                <ActionGroup
                                                    actions={[
                                                        {
                                                            key: 'edit',
                                                            label: t((d) => d.pages.walletAccounts.edit),
                                                            onClick: () => {
                                                                errors.reset();
                                                                setForm(formFor(account));
                                                                setEditing(account);
                                                            },
                                                        },
                                                        {
                                                            key: 'delete',
                                                            label: t((d) => d.common.delete),
                                                            tone: 'destructive' as const,
                                                            onClick: () => {
                                                                setDeleting(account);
                                                            },
                                                        },
                                                    ]}
                                                />
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
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
                    title={t((d) => d.pages.walletAccounts.deleteSelected)}
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
                            toast.error(t((d) => d.common.deleteFailed), {
                                description: describeApiFailure(cause, t),
                            });

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
                title={
                    editing === null
                        ? t((d) => d.pages.walletAccounts.add)
                        : t((d) => d.pages.walletAccounts.edit)
                }
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
                            {t((d) => d.common.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={
                                saving ||
                                form.display_name.trim() === '' ||
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
                            {t((d) => d.common.save)}
                        </PanelButton>
                    </>
                }
            >
                <FieldGrid>
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

                    <Field
                        label={t((d) => d.pages.walletAccounts.form.kind)}
                        htmlFor="account-kind"
                        error={errors.get('kind')}
                    >
                        <PanelSelect
                            id="account-kind"
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
                    >
                        <PanelSelect
                            id="account-currency"
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
                        >
                            <PanelDate
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
                                label={t((d) => d.pages.walletDeposits.form.principal)}
                                htmlFor="account-principal"
                                error={errors.get('principal_minor')}
                                hint={t((d) => d.pages.walletDeposits.form.principalHint)}
                            >
                                <PanelInput
                                    id="account-principal"
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
                                    id="account-term"
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
                                        : t((d) => d.pages.walletDeposits.form.maturityCalculated).replace(
                                              '{days}',
                                              String(termDays),
                                          )
                                }
                            >
                                <PanelInput id="account-maturity" type="date" value={maturityAt} disabled readOnly />
                            </Field>

                            <Field
                                label={t((d) => d.pages.walletDeposits.form.rateType)}
                                htmlFor="account-rate-type"
                                error={errors.get('rate_type')}
                            >
                                <PanelSelect
                                    id="account-rate-type"
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
                                    id="account-method"
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
                                    id="account-frequency"
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
