import { apiRequest } from '../lib/http';
import {
    type AccountList,
    type TransactionPage,
    type WalletOverview,
    parseAccounts,
    parseTransactions,
    parseWalletOverview,
} from '../types/wallet';

/**
 * Wallet reads, all scoped to one tax subject.
 *
 * The subject id is a required argument rather than read from storage inside these functions: it is
 * part of the URL and therefore part of what is being asked for, and a caller that has not chosen a
 * subject should fail at its own call site rather than silently query whichever subject happened to
 * be in `localStorage`.
 */
export async function getWalletOverview(subjectId: string): Promise<WalletOverview> {
    return parseWalletOverview(
        JSON.parse(await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/overview`)),
    );
}

/**
 * `GET .../wallet/accounts` — every account, term deposits included.
 *
 * There is no separate deposits call: a deposit *is* an account, distinguished by carrying terms.
 * The response also brings the deposit form's options, so the one add-form has one source for them.
 */
export async function listAccounts(subjectId: string, includeClosed = false): Promise<AccountList> {
    const query = includeClosed ? '?include_closed=1' : '';

    return parseAccounts(
        JSON.parse(await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/accounts${query}`)),
    );
}

/**
 * `PATCH .../wallet/accounts/{id}` — close an account, or reopen one.
 *
 * Closing is what deleting is not: the account stops appearing among the open ones and stops
 * counting toward totals, while every transaction it holds stays exactly where it is — because last
 * year's return was computed from them. `null` reopens it.
 */
export async function setAccountClosedAt(subjectId: string, accountId: string, closedAt: string | null): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/accounts/${encodeURIComponent(accountId)}`, {
        method: 'PATCH',
        body: { closed_at: closedAt },
    });
}

/**
 * `DELETE .../wallet/accounts/{id}` — remove an account nothing points at.
 *
 * Answers `409` when transactions or interest events still reference it, because deleting would
 * take records a past year's figures were computed from. Closing the account is the answer for one
 * that genuinely existed; deleting is for one that should never have.
 */
export async function deleteAccount(subjectId: string, accountId: string): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/accounts/${encodeURIComponent(accountId)}`, {
        method: 'DELETE',
    });
}

/** `DELETE .../wallet/transactions/{id}` — remove a transaction and the splits that categorise it. */
export async function deleteTransaction(subjectId: string, transactionId: string): Promise<void> {
    await apiRequest(
        `/v1/subjects/${encodeURIComponent(subjectId)}/wallet/transactions/${encodeURIComponent(transactionId)}`,
        { method: 'DELETE' },
    );
}

/**
 * `PUT .../wallet/display-currency` — which currency this subject's totals are added up in.
 *
 * A display preference. It changes how a dashboard sums figures, never how a tax is computed: a
 * fiscal conversion is each rule's own decision, with its own rate, date and source recorded beside
 * the result.
 */
export async function setDisplayCurrency(subjectId: string, currencyCode: string): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/display-currency`, {
        method: 'PUT',
        body: { display_currency: currencyCode },
    });
}

/** Fields the "add account" form collects. Amounts and codes only — nothing derived. */
export interface OpenAccountRequest {
    display_name: string;
    kind: string;
    currency_code: string;
    institution_name?: string;
    iban?: string;
    opened_at?: string;

    /*
     * Deposit terms. Sent only when `kind` is `TERM_DEPOSIT`, and rejected by the server for any
     * other kind — a term deposit is an account, not a separate thing, so there is one form and one
     * endpoint for both.
     *
     * `principal_minor` is an integer in minor units and `annual_rate` a decimal *string*. Neither
     * is a float: both feed an interest calculation whose result lands on a tax return, and binary
     * floating point cannot represent 5.40 exactly.
     */
    principal_minor?: number;
    /**
     * Derived from the chosen term rather than typed. Interest is
     * `principal x rate x days / denominator` and the days are counted to this date, so one that is
     * a day out moves a tax figure with nothing on screen looking wrong.
     */
    maturity_at?: string;
    annual_rate?: string;
    rate_type?: string;
    interest_method?: string;
    payment_frequency?: string;
    /**
     * Optional, and the form never sends it: the server applies the configured default. Present
     * here because the API does accept one, for a contract stating a convention other than the
     * usual Actual/365.
     */
    day_count_convention?: string;
    auto_renewal?: boolean;
    funding_account_id?: string;
}

/**
 * `POST .../wallet/accounts` — the display name and IBAN are encrypted server-side under the
 * subject's own key, so both are sent as plain fields exactly once and never returned again.
 */
export async function openAccount(subjectId: string, body: OpenAccountRequest): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/accounts`, {
        method: 'POST',
        body: { ...body },
    });
}

/**
 * `POST .../wallet/accounts/{id}/balance` — record an observed balance.
 *
 * A snapshot, not an update: an account has no balance column, only a history of observations. Each
 * one carries the date it was taken and whether a human verified it, so a figure derived from it
 * can say which observation it used.
 */
export async function recordBalance(
    subjectId: string,
    accountId: string,
    body: { balance_minor: number; as_of: string; is_verified?: boolean },
): Promise<void> {
    await apiRequest(
        `/v1/subjects/${encodeURIComponent(subjectId)}/wallet/accounts/${encodeURIComponent(accountId)}/balance`,
        { method: 'POST', body: { ...body } },
    );
}

/** `GET .../wallet/transactions` — recent activity, newest first, capped. */
export async function listTransactions(
    subjectId: string,
    filters: { accountId?: string; limit?: number } = {},
): Promise<TransactionPage> {
    const params = new URLSearchParams();

    if (filters.accountId !== undefined && filters.accountId !== '') {
        params.set('account_id', filters.accountId);
    }

    if (filters.limit !== undefined) {
        params.set('limit', String(filters.limit));
    }

    const query = params.toString();

    return parseTransactions(
        JSON.parse(
            await apiRequest(
                `/v1/subjects/${encodeURIComponent(subjectId)}/wallet/transactions${query === '' ? '' : `?${query}`}`,
            ),
        ),
    );
}

export interface RecordTransactionRequest {
    account_id: string;
    /** Signed minor units — negative is money out. */
    amount_minor: number;
    currency_code: string;
    booked_at: string;
    description: string;
    counterparty?: string;
    value_at?: string;
}

export async function recordTransaction(subjectId: string, body: RecordTransactionRequest): Promise<void> {
    await apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/transactions`, {
        method: 'POST',
        body: { ...body },
    });
}
