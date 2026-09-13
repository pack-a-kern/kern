/**
 * Shapes returned by `/api/v1/subjects/{subject}/wallet/*`.
 *
 * Two conventions here are deliberate and worth knowing before reading a number off this screen.
 *
 * **Amounts are minor units** (bani, cents) as integers — never floats. A balance of 72,480.55 RON
 * is `7248055`. Money in floating point accumulates rounding error, and this data ends up on a tax
 * return; formatting happens once, at the edge, in {@link formatMinor}.
 *
 * **Totals are per currency**, keyed by code. There is no single "total balance", because adding
 * RON to EUR requires a rate, a date and a source — a fiscal decision the tax engine makes with
 * provenance, not something a dashboard may invent.
 */
/**
 * What an account additionally is, when it is a term deposit.
 *
 * On the account row rather than from a second call, because a deposit *is* an account — the
 * separate table and the separate screen it fed are what made them look like different things.
 */
export interface DepositFacts {
    /** The deposit's own currency — the source holding it may have none. */
    currencyCode: string;
    startsAt: string;
    principalMinor: number;
    maturityAt: string;
    /** The rate of the period covering today; a deposit has a series, not one rate. */
    annualRate: string | null;
    rateType: string;
    interestMethod: string;
    paymentFrequency: string;
    dayCountConvention: string;
    autoRenewal: boolean;
    /** Gross interest over the whole term — what it will have earned by maturity, not so far. */
    interestAtMaturityMinor: number | null;
    totalDays: number;
    /** Negative once matured. */
    daysToMaturity: number;
}

/** One currency's worth of a source, summed from the transactions recorded against it. */
export interface AccountBalance {
    currencyCode: string;
    amountMinor: number;
}

export interface AccountSummary {
    id: string;
    displayName: string;
    kind: string;
    /** A default for the transaction form; null for a source that holds several currencies. */
    currencyCode: string | null;
    /**
     * One figure per currency, derived from the transactions recorded against this source.
     *
     * A list because a source is a folder: a broker holds euros and dollars, a crypto exchange
     * holds several assets. An empty list means nothing has been recorded — which is different from
     * zero, and the screen says so.
     */
    balances: AccountBalance[];
    source: string;
    /** Null for every kind of source except a term deposit. */
    deposit: DepositFacts | null;
}

export interface DepositSummary {
    id: string;
    displayName: string;
    currencyCode: string;
    principalMinor: number;
    /** The rate of the period covering today; a deposit has a series, not one rate. */
    annualRate: string | null;
    rateType: string;
    paymentFrequency: string;
    openedAt: string;
    maturityAt: string;
    autoRenewal: boolean;
    /**
     * Gross interest over the deposit's **whole term**, not the part earned so far — computed when
     * the deposit is opened, from inputs that are all fixed at that moment. `null` only when no
     * schedule exists, which is distinct from having earned nothing.
     */
    interestAtMaturityMinor: number | null;
    /** The term as bought, in days — the span the interest above was calculated over. */
    totalDays: number;
    /** Negative once matured. */
    daysToMaturity: number | null;
}

/**
 * Form options the deposits endpoint sends alongside the list, so the terms a person can pick come
 * from `config/wallet.php` rather than from literals in this bundle.
 *
 * The day-count convention is not among them. It is applied server-side from the same config: it is
 * set by the bank's contract rather than chosen by the depositor, so the form neither asks for it
 * nor echoes it back.
 */
export interface DepositFormOptions {
    /** Terms offered by the select, in months. */
    termsMonths: number[];
}

/**
 * One transaction as the screen shows it.
 *
 * `amountMinor` is signed — negative is money out. A separate direction field would let the sign
 * and the direction contradict each other.
 */
export interface TransactionSummary {
    id: string;
    accountId: string;
    accountName: string;
    bookedAt: string;
    valueAt: string | null;
    amountMinor: number;
    currencyCode: string;
    description: string;
    counterparty: string | null;
    /** Whether this money has been matched to something that explains it. */
    classificationStatus: string;
    source: string;
}

export interface TransactionPage {
    transactions: TransactionSummary[];
    limit: number;
    /** The list is a window, not the whole history — the screen says so when it fills. */
    capped: boolean;
}

/**
 * Everything the subject holds, added into one currency for display.
 *
 * `rates` and `rateDate` come with it because a single number without them is a claim nobody can
 * check — the screen shows the rate and the day it was published beside the figure.
 *
 * `complete` is false when a currency had no published rate and was left out. A total that quietly
 * omits a currency reads as smaller wealth rather than as missing data, so the screen says so.
 *
 * Never a fiscal figure: a tax conversion is each rule's own decision, with its own provenance.
 */
export interface NetWorth {
    currencyCode: string;
    amountMinor: number;
    /** Per source currency: the per-unit rate used and the date it was published. */
    rates: Record<string, { rate: string; rateDate: string; source: string }>;
    /** Currencies excluded for want of a rate. */
    missing: string[];
    complete: boolean;
}

export interface WalletTotals {
    balances: Record<string, number>;
    deposits: Record<string, number>;
    /** What the running deposits will have earned by maturity — a forecast, labelled as one. */
    interestAtMaturity: Record<string, number>;
    accounts: number;
    depositCount: number;
    /** Safe to show as one number: already converted at the BNR rate for each accrual date. */
    recognisedRonMinor: number | null;
    netWorth: NetWorth;
}

export interface WalletOverview {
    totals: WalletTotals;
    accounts: AccountSummary[];
    accountsTotal: number;
    maturing: DepositSummary[];
    maturingTotal: number;
}

/**
 * The enum cases the add-account form offers, in the order they appear.
 *
 * Mirrors `App\Domain\Wallet\Enums\AccountKind`; `WalletLabelCoverageTest` asserts every case has
 * a label, and the server re-validates with `Rule::enum`, so a stale entry here is rejected rather
 * than stored.
 */
/**
 * The kinds a source can be, in the order a form offers them.
 *
 * `GENERAL` is created with the taxpayer and never chosen from a form, so it is listed for labelling
 * but filtered out of the "new source" select — see `STREAM_KINDS` for the split that decides where
 * a transaction may be recorded.
 */
export const ACCOUNT_KINDS = ['GENERAL', 'CASH', 'TERM_DEPOSIT', 'BROKER', 'CRYPTO', 'OTHER'] as const;

/**
 * Sources that receive transactions: typed by hand or parsed out of a statement.
 *
 * The others — cash and term deposits — are a single declared value or a schedule computed from
 * one, so posting an arbitrary transaction into them detaches the ledger from the thing it
 * describes. The form offers only these; the server enforces the same rule where a form cannot.
 */
export const STREAM_KINDS = ['GENERAL', 'BROKER', 'CRYPTO', 'OTHER'] as const;

export type StreamKind = (typeof STREAM_KINDS)[number];

/**
 * The kinds a person can actually open.
 *
 * `GENERAL` is not among them: it is created with the taxpayer and there is exactly one, so
 * offering it in a form would invite a second that means nothing.
 */
export const OPENABLE_KINDS = ACCOUNT_KINDS.filter((kind) => kind !== 'GENERAL');

/** Whether transactions may be recorded against a source of this kind. */
export function isStreamKind(kind: string): boolean {
    return (STREAM_KINDS as readonly string[]).includes(kind);
}

/**
 * What to call a source on screen.
 *
 * `GENERAL` is the system's own source — created with the taxpayer, never named by anyone — so it
 * carries no stored name and the screen supplies one. Everything else is called what its owner
 * called it.
 */
export function sourceName(account: { kind: string; displayName: string }, generalLabel: string): string {
    return account.kind === 'GENERAL' ? generalLabel : account.displayName;
}


/**
 * Currencies offered in the forms. RON first because this is a Romanian tax product; the rest are
 * what a Romanian taxpayer plausibly holds. Not an allowlist — the API accepts any ISO 4217 code,
 * this is just what the select shows.
 */
export const CURRENCIES = ['RON', 'EUR', 'USD', 'GBP', 'CHF'] as const;

/** Mirrors `App\Domain\Wallet\Enums\RateType`. */
export const RATE_TYPES = ['FIXED', 'VARIABLE'] as const;

/** Mirrors `App\Domain\Wallet\Enums\InterestMethod`. */
export const INTEREST_METHODS = ['SIMPLE', 'COMPOUND'] as const;

/** Mirrors `App\Domain\Wallet\Enums\PaymentFrequency`. */
export const PAYMENT_FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'SEMI_ANNUAL', 'ANNUAL', 'AT_MATURITY'] as const;

/*
 * There is deliberately no `DAY_COUNTS` constant beside these. The convention is not offered as a
 * choice: it is set by the bank's contract, and picking the wrong one moves the interest by about
 * 1.4% (365 against 360 as the denominator) with nothing about the result looking wrong. The
 * deposits endpoint sends the configured default, which the form shows read-only. The labels for
 * every case still live in the dictionaries, because that value has to be rendered.
 */

/**
 * Minor units to a display string — the one place the integer becomes a decimal.
 *
 * `locale` is required rather than defaulting to `ro-RO`: this app ships in two languages, and a
 * default would quietly format an English page with Romanian separators. Callers pass the locale
 * `useTranslation()` already gives them.
 */
export function formatMinor(minor: number | null, currency: string, locale: string): string {
    if (minor === null) {
        return '—';
    }

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100);
}

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

function str(row: Record<string, unknown>, key: string): string {
    const value = row[key];

    return typeof value === 'string' ? value : '';
}

function nullableStr(row: Record<string, unknown>, key: string): string | null {
    const value = row[key];

    return typeof value === 'string' ? value : null;
}

function num(row: Record<string, unknown>, key: string): number {
    const value = row[key];

    return typeof value === 'number' ? value : 0;
}

function nullableNum(row: Record<string, unknown>, key: string): number | null {
    const value = row[key];

    return typeof value === 'number' ? value : null;
}

function money(value: unknown): Record<string, number> {
    const source = obj(value);
    const out: Record<string, number> = {};

    for (const [code, amount] of Object.entries(source)) {
        if (typeof amount === 'number') {
            out[code] = amount;
        }
    }

    return out;
}

function parseNetWorth(payload: unknown): NetWorth {
    const row = obj(payload);
    const rates: NetWorth['rates'] = {};

    for (const [currency, detail] of Object.entries(obj(row.rates))) {
        const entry = obj(detail);

        rates[currency] = {
            rate: str(entry, 'rate'),
            rateDate: str(entry, 'rate_date'),
            source: str(entry, 'source'),
        };
    }

    return {
        currencyCode: str(row, 'currency_code'),
        amountMinor: num(row, 'amount_minor'),
        rates,
        missing: (Array.isArray(row.missing) ? row.missing : []).filter(
            (code): code is string => typeof code === 'string',
        ),
        // Absent means incomplete rather than complete: a total is only trustworthy when the server
        // said so, and defaulting the other way would hide exactly the case this flag exists for.
        complete: row.complete === true,
    };
}

function parseAccount(payload: unknown): AccountSummary {
    const row = obj(payload);

    return {
        id: str(row, 'id'),
        displayName: str(row, 'display_name'),
        kind: str(row, 'kind'),
        currencyCode: nullableStr(row, 'currency_code'),
        balances: (Array.isArray(row.balances) ? row.balances : []).map((entry): AccountBalance => {
            const balance = obj(entry);

            return {
                currencyCode: str(balance, 'currency_code'),
                amountMinor: num(balance, 'amount_minor'),
            };
        }),
        source: str(row, 'source'),
        deposit: parseDepositFacts(row.deposit),
    };
}

function parseDepositFacts(payload: unknown): DepositFacts | null {
    if (payload === null || payload === undefined) {
        return null;
    }

    const row = obj(payload);

    return {
        currencyCode: str(row, 'currency_code'),
        startsAt: str(row, 'starts_at'),
        principalMinor: num(row, 'principal_minor'),
        maturityAt: str(row, 'maturity_at'),
        annualRate: nullableStr(row, 'annual_rate'),
        rateType: str(row, 'rate_type'),
        interestMethod: str(row, 'interest_method'),
        paymentFrequency: str(row, 'payment_frequency'),
        dayCountConvention: str(row, 'day_count_convention'),
        autoRenewal: row.auto_renewal === true,
        interestAtMaturityMinor: nullableNum(row, 'interest_at_maturity_minor'),
        totalDays: num(row, 'total_days'),
        daysToMaturity: num(row, 'days_to_maturity'),
    };
}

function parseDeposit(payload: unknown): DepositSummary {
    const row = obj(payload);

    return {
        id: str(row, 'id'),
        displayName: str(row, 'display_name'),
        currencyCode: str(row, 'currency_code'),
        principalMinor: num(row, 'principal_minor'),
        annualRate: nullableStr(row, 'annual_rate'),
        rateType: str(row, 'rate_type'),
        paymentFrequency: str(row, 'payment_frequency'),
        openedAt: str(row, 'opened_at'),
        maturityAt: str(row, 'maturity_at'),
        autoRenewal: row.auto_renewal === true,
        interestAtMaturityMinor: nullableNum(row, 'interest_at_maturity_minor'),
        totalDays: num(row, 'total_days'),
        daysToMaturity: nullableNum(row, 'days_to_maturity'),
    };
}

export interface AccountList {
    accounts: AccountSummary[];
    /** The deposit form's options, served with accounts because a deposit *is* an account. */
    formOptions: DepositFormOptions;
}

export function parseAccounts(payload: unknown): AccountList {
    const body = obj(payload);
    const options = obj(body.form_options);

    return {
        accounts: (Array.isArray(body.accounts) ? body.accounts : []).map(parseAccount),
        formOptions: {
            termsMonths: (Array.isArray(options.terms_months) ? options.terms_months : []).filter(
                (months): months is number => typeof months === 'number' && Number.isInteger(months) && months > 0,
            ),
        },
    };
}

export function parseTransactions(payload: unknown): TransactionPage {
    const body = obj(payload);

    return {
        transactions: (Array.isArray(body.transactions) ? body.transactions : []).map((entry): TransactionSummary => {
            const row = obj(entry);

            return {
                id: str(row, 'id'),
                accountId: str(row, 'account_id'),
                accountName: str(row, 'account_name'),
                bookedAt: str(row, 'booked_at'),
                valueAt: nullableStr(row, 'value_at'),
                amountMinor: num(row, 'amount_minor'),
                currencyCode: str(row, 'currency_code'),
                description: str(row, 'description'),
                counterparty: nullableStr(row, 'counterparty'),
                classificationStatus: str(row, 'classification_status'),
                source: str(row, 'source'),
            };
        }),
        limit: num(body, 'limit'),
        capped: body.capped === true,
    };
}

export function parseWalletOverview(payload: unknown): WalletOverview {
    const body = obj(payload);
    const totals = obj(body.totals);

    return {
        totals: {
            balances: money(totals.balances),
            deposits: money(totals.deposits),
            interestAtMaturity: money(totals.interest_at_maturity),
            accounts: num(totals, 'accounts'),
            depositCount: num(totals, 'deposit_count'),
            recognisedRonMinor: nullableNum(totals, 'recognised_ron_minor'),
            netWorth: parseNetWorth(totals.net_worth),
        },
        accounts: (Array.isArray(body.accounts) ? body.accounts : []).map(parseAccount),
        accountsTotal: num(body, 'accounts_total'),
        maturing: (Array.isArray(body.maturing) ? body.maturing : []).map(parseDeposit),
        maturingTotal: num(body, 'maturing_total'),
    };
}
