import { useEffect, useState, type JSX } from 'react';

import { deleteAccount, listAccounts, openAccount, recordBalance, setAccountClosedAt } from '../../api/walletApi';
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
    Status,
    Toolbar,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { Switch } from '../../components/ui/switch';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import { addMonths, daysBetween } from '../../lib/depositTerm';
import { ApiError } from '../../lib/http';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { toast } from 'sonner';

import {
    ACCOUNT_KINDS,
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
    institution_name: string;
    kind: string;
    currency_code: string;
    iban: string;
    opened_at: string;

    /* Term deposit only — see `AccountForm`. */
    principal: string;
    term_months: string;
    annual_rate: string;
    rate_type: string;
    interest_method: string;
    payment_frequency: string;
    auto_renewal: boolean;
}

function emptyForm(): AccountForm {
    return {
        display_name: '',
        institution_name: '',
        // Defaults chosen so the commonest account is one field away: a RON current account.
        kind: ACCOUNT_KINDS[0],
        currency_code: CURRENCIES[0],
        iban: '',
        opened_at: '',
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

    // The filter is stored *with* the rows it produced, so "the data does not match the current
    // filter" is what means loading. Nulling the rows inside the effect body would set state
    // synchronously during render, which `react-hooks/set-state-in-effect` correctly rejects.
    const [loaded, setLoaded] = useState<{
        includeClosed: boolean;
        rows: AccountSummary[];
        options: DepositFormOptions;
    } | null>(null);
    const [includeClosed, setIncludeClosed] = useState(false);
    const [failed, setFailed] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);

    const errors = useFormErrors();
    const balanceErrors = useFormErrors();
    const [balanceFor, setBalanceFor] = useState<AccountSummary | null>(null);
    const [deleting, setDeleting] = useState<AccountSummary | null>(null);
    const [balanceForm, setBalanceForm] = useState({ amount: '', as_of: '', is_verified: false });
    const [adding, setAdding] = useState(false);
    const [saving, setSaving] = useState(false);
    // Typed explicitly: `ACCOUNT_KINDS[0]` is a const-asserted tuple, so inference would pin
    // `kind` to the literal 'BANK_CURRENT' and reject every other value the select offers.
    const [form, setForm] = useState<AccountForm>(emptyForm());

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listAccounts(subjectId, includeClosed).then(
            (page) => {
                if (active) {
                    setLoaded({ includeClosed, rows: page.accounts, options: page.formOptions });
                    setFailed(false);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                    setLoaded({ includeClosed, rows: [], options: { termsMonths: [] } });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, includeClosed, reloadKey]);

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

    const accounts = loaded !== null && loaded.includeClosed === includeClosed ? loaded.rows : null;

    // A term deposit is an account with extra terms, so the form grows rather than the app offering
    // a second place to add one. This is the whole reason `deposits` stopped being its own table.
    const options: DepositFormOptions = loaded?.options ?? { termsMonths: [] };
    const isDeposit = form.kind === 'TERM_DEPOSIT';

    // Derived on every render rather than stored: a second copy of the maturity could drift out of
    // step with the term that produced it, which is what asking for a term instead of a date fixes.
    const termMonths = Number(form.term_months);
    const maturityAt =
        form.opened_at !== '' && Number.isInteger(termMonths) && termMonths > 0
            ? addMonths(form.opened_at, termMonths)
            : '';
    const termDays = maturityAt === '' ? null : daysBetween(form.opened_at, maturityAt);

    /** Labels a term the way it is sold — "6 luni", and the singular for one month. */
    const termLabel = (months: number): string =>
        months === 1
            ? t((d) => d.pages.walletDeposits.form.termOneMonth)
            : t((d) => d.pages.walletDeposits.form.termMonths).replace('{months}', String(months));

    // Neither handler re-checks `subjectId`: the early return above this point already narrowed it
    // to a string, and TypeScript rejects the redundant check as unreachable.
    const submitBalance = async (): Promise<void> => {
        if (balanceFor === null) {
            return;
        }

        setSaving(true);
        balanceErrors.reset();

        try {
            await recordBalance(subjectId, balanceFor.id, {
                // Major units in the form, minor units on the wire — rounded once, here.
                balance_minor: Math.round(Number(balanceForm.amount) * 100),
                as_of: balanceForm.as_of,
                is_verified: balanceForm.is_verified,
            });

            toast.success(t((d) => d.pages.walletAccounts.balanceRecorded));
            setBalanceFor(null);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            if (!balanceErrors.capture(cause)) {
                toast.error(t((d) => d.pages.walletAccounts.balanceFailed));
            }
        } finally {
            setSaving(false);
        }
    };

    /**
     * Closes an open account, or reopens a closed one.
     *
     * Dated today rather than asking: a person closing an account is recording that it is closed
     * now, and a wrong date is fixable while an extra dialog in the way of the common case is not
     * worth it.
     */
    const toggleClosed = async (account: AccountSummary): Promise<void> => {
        try {
            await setAccountClosedAt(
                subjectId,
                account.id,
                account.closed ? null : new Date().toISOString().slice(0, 10),
            );
            toast.success(
                account.closed
                    ? t((d) => d.pages.walletAccounts.reopened)
                    : t((d) => d.pages.walletAccounts.closedToast),
            );
            setReloadKey((current) => current + 1);
        } catch {
            toast.error(t((d) => d.pages.walletAccounts.closeFailed));
        }
    };

    const submit = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            await openAccount(subjectId, {
                display_name: form.display_name,
                kind: form.kind,
                currency_code: form.currency_code,
                ...(form.institution_name === '' ? {} : { institution_name: form.institution_name }),
                ...(form.iban === '' ? {} : { iban: form.iban }),
                ...(form.opened_at === '' ? {} : { opened_at: form.opened_at }),
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
            });

            toast.success(t((d) => d.pages.walletAccounts.added));
            setAdding(false);
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
                    footNote={t((d) => d.pages.wallet.perCurrencyNote)}
                    flush
                >
                    <Toolbar>
                        <PanelButton
                            primary
                            onClick={() => {
                                errors.reset();
                                // Defaults set in the handler that opens the dialog, not an effect:
                                // the terms arrive with the account list, so by the time this can be
                                // pressed they are known.
                                setForm((current) => ({
                                    ...current,
                                    term_months: String(options.termsMonths[0] ?? ''),
                                }));
                                setAdding(true);
                            }}
                        >
                            {t((d) => d.pages.walletAccounts.add)}
                        </PanelButton>
                        <label className="flex items-center gap-2 text-xs">
                            <Switch
                                checked={includeClosed}
                                onCheckedChange={setIncludeClosed}
                                aria-label={t((d) => d.pages.walletAccounts.includeClosed)}
                            />
                            {t((d) => d.pages.walletAccounts.includeClosed)}
                        </label>
                    </Toolbar>

                    {accounts === null ? (
                        <TableSkeleton />
                    ) : failed ? (
                        <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                    ) : accounts.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.walletOverview.noAccounts)}</p>
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.walletAccounts.columns.account)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.type)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.currency)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.balance)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.source)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.lastUpdate)}</th>
                                    <th>{t((d) => d.pages.walletAccounts.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {accounts.map((account) => (
                                <tr key={account.id}>
                                    <td className="col-primary">
                                        <div className="font-semibold">{account.displayName}</div>
                                        <div className="note">
                                            {account.institutionName}
                                            {account.ibanLast4 === null ? '' : ` · •••• ${account.ibanLast4}`}
                                        </div>
                                    </td>
                                    <td className="col-hide">
                                        <Pill>{t((d) => d.pages.wallet.kinds[account.kind] ?? account.kind)}</Pill>
                                    </td>
                                    <td>{account.currencyCode}</td>
                                    <td className="tabular-nums whitespace-nowrap">
                                        {/* A deposit's money is its principal, not an observed
                                            balance: nobody takes snapshots of a locked account, so
                                            showing "unknown" there would be misleading. The interest
                                            it will have earned by maturity goes underneath, because
                                            that is the number a deposit is opened to find out. */}
                                        {account.deposit !== null ? (
                                            <>
                                                {formatMinor(
                                                    account.deposit.principalMinor,
                                                    account.currencyCode,
                                                    locale,
                                                )}
                                                <div className="note">
                                                    {account.deposit.interestAtMaturityMinor === null
                                                        ? t((d) => d.pages.walletDeposits.notComputed)
                                                        : `+ ${formatMinor(
                                                              account.deposit.interestAtMaturityMinor,
                                                              account.currencyCode,
                                                              locale,
                                                          )}`}
                                                    {account.deposit.annualRate === null
                                                        ? ''
                                                        : ` · ${account.deposit.annualRate}%`}
                                                </div>
                                            </>
                                        ) : account.balanceMinor === null ? (
                                            <span className="note">{t((d) => d.pages.wallet.unknownBalance)}</span>
                                        ) : (
                                            formatMinor(account.balanceMinor, account.currencyCode, locale)
                                        )}
                                    </td>
                                    <td className="col-hide">
                                        <Pill>
                                            {t(
                                                (d) =>
                                                    d.pages.wallet.sources[account.balanceSource ?? account.source] ??
                                                    account.source,
                                            )}
                                        </Pill>
                                        {/* A balance is only as good as its verification: an imported
                                            figure a bank confirmed and one typed in by hand are not
                                            interchangeable when reconciling a tax year. */}
                                        <div className="note">
                                            {account.balanceMinor === null
                                                ? ''
                                                : account.balanceVerified
                                                  ? t((d) => d.pages.wallet.verified)
                                                  : account.balanceIsDerived
                                                    ? t((d) => d.pages.wallet.derived)
                                                    : t((d) => d.pages.wallet.unverified)}
                                        </div>
                                    </td>
                                    <td className="col-hide whitespace-nowrap">
                                        {account.balanceAsOf === null
                                            ? t((d) => d.pages.walletAccounts.never)
                                            : new Date(account.balanceAsOf).toLocaleDateString()}
                                    </td>
                                    <td>
                                        {account.deposit !== null && !account.closed ? (
                                            <Status tone={account.deposit.daysToMaturity < 0 ? 'idle' : 'ok'}>
                                                {account.deposit.daysToMaturity < 0
                                                    ? t((d) => d.pages.walletDeposits.matured)
                                                    : t((d) => d.pages.walletDeposits.daysLeft).replace(
                                                          '{days}',
                                                          String(account.deposit.daysToMaturity),
                                                      )}
                                            </Status>
                                        ) : (
                                            <Status tone={account.closed ? 'idle' : 'ok'}>
                                                {account.closed
                                                    ? t((d) => d.pages.walletAccounts.closed)
                                                    : t((d) => d.pages.walletAccounts.active)}
                                            </Status>
                                        )}
                                    </td>
                                    <td className="col-end text-right">
                                        <div className="flex justify-end gap-2">
                                            {/* Not offered for a term deposit: its money is a fixed
                                                principal locked until maturity, so there is no
                                                observed balance to record. Showing the button would
                                                invite a snapshot that means nothing. */}
                                            {account.deposit === null ? (
                                                <PanelButton
                                                    disabled={account.closed}
                                                    onClick={() => {
                                                        balanceErrors.reset();
                                                        setBalanceForm({
                                                            amount: '',
                                                            // Defaults to today: a balance is almost
                                                            // always recorded as of now.
                                                            as_of: new Date().toISOString().slice(0, 10),
                                                            is_verified: false,
                                                        });
                                                        setBalanceFor(account);
                                                    }}
                                                >
                                                    {t((d) => d.pages.walletAccounts.updateBalance)}
                                                </PanelButton>
                                            ) : null}
                                            {/* Closing is the ordinary end of an account's life and
                                                deleting is the exception, so the two sit together:
                                                refusing a deletion and pointing at "close it
                                                instead" is useless if there is nowhere to do it. */}
                                            <PanelButton
                                                onClick={() => {
                                                    void toggleClosed(account);
                                                }}
                                            >
                                                {account.closed
                                                    ? t((d) => d.pages.walletAccounts.reopen)
                                                    : t((d) => d.pages.walletAccounts.close)}
                                            </PanelButton>
                                            <PanelButton
                                                variant="ghost"
                                                onClick={() => {
                                                    setDeleting(account);
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
            </Page>

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
                            // 409 is the server refusing because records still explain a figure
                            // through this account — a different situation from a failed request,
                            // and the remedy is to close it instead.
                            toast.error(
                                cause instanceof ApiError && cause.status === 409
                                    ? t((d) => d.pages.walletAccounts.deleteBlocked)
                                    : t((d) => d.common.deleteFailed),
                            );

                            throw cause;
                        }
                    }}
                />
            )}

            <PanelDialog
                open={balanceFor !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setBalanceFor(null);
                    }
                }}
                title={t((d) => d.pages.walletAccounts.updateBalance)}
                description={t((d) => d.pages.walletAccounts.updateBalanceHint)}
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setBalanceFor(null);
                            }}
                        >
                            {t((d) => d.common.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={saving || balanceForm.amount.trim() === '' || balanceForm.as_of === ''}
                            onClick={() => {
                                void submitBalance();
                            }}
                        >
                            {t((d) => d.common.save)}
                        </PanelButton>
                    </>
                }
            >
                {balanceFor === null ? null : (
                    <div className="space-y-3">
                        <p className="note">
                            {balanceFor.displayName} · {balanceFor.currencyCode}
                        </p>

                        <Field
                            label={t((d) => d.pages.walletAccounts.balanceAmount)}
                            htmlFor="balance-amount"
                            error={balanceErrors.get('balance_minor')}
                            hint={t((d) => d.pages.walletAccounts.balanceAmountHint)}
                        >
                            <PanelInput
                                id="balance-amount"
                                type="number"
                                step="0.01"
                                value={balanceForm.amount}
                                onChange={(event) => {
                                    setBalanceForm((current) => ({ ...current, amount: event.target.value }));
                                }}
                            />
                        </Field>

                        <Field
                            label={t((d) => d.pages.walletAccounts.balanceAsOf)}
                            htmlFor="balance-as-of"
                            error={balanceErrors.get('as_of')}
                        >
                            <PanelInput
                                id="balance-as-of"
                                type="date"
                                value={balanceForm.as_of}
                                onChange={(event) => {
                                    setBalanceForm((current) => ({ ...current, as_of: event.target.value }));
                                }}
                            />
                        </Field>

                        <Field
                            label={t((d) => d.pages.walletAccounts.balanceVerified)}
                            hint={t((d) => d.pages.walletAccounts.balanceVerifiedHint)}
                        >
                            <Switch
                                checked={balanceForm.is_verified}
                                onCheckedChange={(checked) => {
                                    setBalanceForm((current) => ({ ...current, is_verified: checked }));
                                }}
                                aria-label={t((d) => d.pages.walletAccounts.balanceVerified)}
                            />
                        </Field>
                    </div>
                )}
            </PanelDialog>

            <PanelDialog
                open={adding}
                onOpenChange={setAdding}
                title={t((d) => d.pages.walletAccounts.add)}
                description={t((d) => d.pages.walletAccounts.addHint)}
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
                                form.display_name.trim() === '' ||
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
                            value={form.display_name}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, display_name: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletAccounts.form.institution)}
                        htmlFor="account-institution"
                        error={errors.get('institution_name')}
                    >
                        <PanelInput
                            id="account-institution"
                            value={form.institution_name}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, institution_name: event.target.value }));
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
                            options={ACCOUNT_KINDS.map((kind) => ({
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

                    <Field
                        label={t((d) => d.pages.walletAccounts.form.iban)}
                        htmlFor="account-iban"
                        error={errors.get('iban')}
                        hint={t((d) => d.pages.walletAccounts.form.ibanHint)}
                    >
                        <PanelInput
                            id="account-iban"
                            value={form.iban}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, iban: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletAccounts.form.openedAt)}
                        htmlFor="account-opened"
                        error={errors.get('opened_at')}
                    >
                        <PanelInput
                            id="account-opened"
                            type="date"
                            value={form.opened_at}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, opened_at: event.target.value }));
                            }}
                        />
                    </Field>

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
