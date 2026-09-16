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
    /** Matured and settled: its money is back where it came from, and nothing about it changes. */
    closed: boolean;
    /** Interest is on the ledger, so the terms it was computed from can no longer change. */
    interestPosted: boolean;
    /** Interest already paid into it, net; 0 until the first period is posted. */
    interestReceivedMinor: number;
    /** Which source the principal came out of; null when it came from one nobody tracks. */
    fundingAccountId: string | null;
    /** The bank it is at; null for a deposit recorded before banks were imported. */
    bankId: string | null;
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
    closed: boolean;
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
/**
 * One meaning given to part of a transaction.
 *
 * `countryCode` is null for Romania. It sits beside the category rather than inside it because
 * Declarația Unică declares foreign income in its own section, per country *and* per type — so
 * `RENT` + `DE` and `RENT` + `RO` are different rows on the form.
 */
export interface TransactionSplit {
    amountMinor: number;
    categoryCode: string;
    countryCode: string | null;
}

/** Which way money moves. The sign of a stored amount, chosen in a form as a toggle. */
export type MoneyDirection = 'in' | 'out';

/**
 * A person's own label for their spending — "restaurants", "fuel".
 *
 * Not a tax category: every one of these is `EXPENSE` as far as a return is concerned. Tags exist to
 * filter and to answer "what did I spend on food last year", and the name is decrypted for its owner.
 */
export interface Tag {
    id: string;
    name: string;
}

export function parseTags(payload: unknown): Tag[] {
    const body = obj(payload);

    return (Array.isArray(body.tags) ? body.tags : []).map((entry): Tag => {
        const row = obj(entry);

        return { id: str(row, 'id'), name: str(row, 'name') };
    });
}

/** A category a transaction may be said to be, from `config/tax_categories.php`. */
export interface TaxCategory {
    code: string;
    /** Groups the dropdown, and says which totals it joins. Not a tax rule. */
    income: boolean;
    /** The way money filed under it can move; the server refuses any other. */
    direction: MoneyDirection | 'either';
    /** Offered already chosen when money moves in this category's only direction. */
    preselected: boolean;
}

/** The categories money moving this way can be given. */
export function categoriesFor(categories: TaxCategory[], direction: MoneyDirection): TaxCategory[] {
    return categories.filter((category) => category.direction === 'either' || category.direction === direction);
}

/**
 * The category to show once the direction is known: the one already chosen while it still fits,
 * otherwise the catalogue's preselection for that direction, otherwise none. Flipping the toggle on a
 * dividend must not leave "Dividends" selected on money going out, which the server would refuse.
 */
export function categoryFor(categories: TaxCategory[], code: string, direction: MoneyDirection): string {
    const fitting = categoriesFor(categories, direction);

    if (fitting.some((category) => category.code === code)) {
        return code;
    }

    return fitting.find((category) => category.preselected && category.direction === direction)?.code ?? '';
}

/** A typed major amount and a direction as signed minor units — rounded once, so no fractional bani reach the API. */
export function signedMinor(amount: string, direction: MoneyDirection): number {
    const magnitude = Math.round(Math.abs(Number(amount)) * 100);

    return direction === 'out' ? -magnitude : magnitude;
}

export interface TransactionSummary {
    id: string;
    accountId: string;
    accountName: string;
    /** The source's kind. The general source has no stored name; the screen names it by kind. */
    accountKind: string;
    bookedAt: string;
    amountMinor: number;
    currencyCode: string;
    description: string;
    counterparty: string | null;
    /** Whether this money has been matched to something that explains it. */
    classificationStatus: string;
    /** What it was said to be. Empty until someone answers. */
    splits: TransactionSplit[];
    source: string;
    /** The document it was built from. A merge keeps the document-backed row. */
    documentId: string | null;
    /** Where it happened, when it is known. The money still sits in its account. */
    bankId: string | null;
    bankName: string | null;
    /** The person's own labels. Never a tax vocabulary — see {@link Tag}. */
    tags: Tag[];
    /** The statement import that created it, for undoing a whole import. */
    importId: string | null;
}

/** Lookalike transactions gathered for a person to merge or keep. */
export interface DuplicateGroup {
    /** The row a merge keeps — decided on the server by the same rule the merge applies. */
    survivorId: string;
    transactions: TransactionSummary[];
}

export interface TransactionPage {
    transactions: TransactionSummary[];
    /** Rows matching the filters, across every page — what the pager counts. */
    total: number;
    page: number;
    perPage: number;
    /** The vocabulary the classification dialog offers; the server validates against the same list. */
    categories: TaxCategory[];
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

/** Amounts per currency, and the same amounts in the display currency. */
export interface Holding {
    amounts: Record<string, number>;
    converted: NetWorth;
}

export interface WalletTotals {
    /** Everything added together in the display currency — an indication, never a fiscal figure. */
    netWorth: NetWorth;
    holdings: {
        /** Recorded in General, never below zero. */
        general: Holding;
        /** Running principal, plus interest paid into a deposit and not yet settled. */
        deposits: Holding;
        cash: Holding;
        /** Broker, crypto and other accounts, never below zero. */
        other: Holding;
        /** What can be spent today: General and cash together. */
        available: Holding;
    };
    depositCount: number;
    /** What the running deposits will have earned by maturity — gross, a forecast. */
    interestAtMaturity: Holding;
    /** Interest already paid, net. */
    interestReceived: Holding;
    /** Lent and not yet paid back. Beside what the taxpayer has, never part of it. */
    owedToMe: Holding;
    /** Safe to show as one number: already converted at the BNR rate for each accrual date. */
    recognisedRonMinor: number | null;
}

/** The running deposits at one bank. */
export interface BankDeposits {
    /** Null, with `name` and `code`, for deposits recorded before banks were imported. */
    bankId: string | null;
    name: string | null;
    code: string | null;
    depositCount: number;
    principal: Record<string, number>;
    interestAtMaturity: Record<string, number>;
    converted: NetWorth;
    nextMaturityAt: string;
}

export interface WalletOverview {
    totals: WalletTotals;
    banks: BankDeposits[];
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
        closed: row.closed === true,
        interestPosted: row.interest_posted === true,
        interestReceivedMinor: num(row, 'interest_received_minor'),
        fundingAccountId: nullableStr(row, 'funding_account_id'),
        bankId: nullableStr(row, 'bank_id'),
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
        closed: row.closed === true,
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

/** One catalogue entry, from `ShowTaxCategoriesController` or the transactions list. */
function parseCategory(entry: unknown): TaxCategory {
    const category = obj(entry);

    const direction = str(category, 'direction');

    return {
        code: str(category, 'code'),
        income: category.income === true,
        // An unknown value reads as the narrowest answer rather than as "either": offering a category
        // nowhere is a missing option, offering it everywhere is a refused save.
        direction: direction === 'in' || direction === 'out' || direction === 'either' ? direction : 'in',
        preselected: category.preselected === true,
    };
}

/** A bank a deposit can be opened at, from the imported ECB list. */
export interface Bank {
    id: string;
    /** Built from the registered name — "Banca Transilvania", not "BANCA TRANSILVANIA S.A.". */
    name: string;
    /** The 4-letter code from the bank's BIC, or null where none is mapped. */
    code: string | null;
    countryCode: string;
}

export function parseBanks(payload: unknown): Bank[] {
    const body = obj(payload);

    return (Array.isArray(body.banks) ? body.banks : []).map((entry): Bank => {
        const row = obj(entry);

        return {
            id: str(row, 'id'),
            name: str(row, 'name'),
            code: nullableStr(row, 'code'),
            countryCode: str(row, 'country_code'),
        };
    });
}

/** How a bank reads in a list: its name, and its code beside it where it has one. */
export function bankLabel(bank: Bank): string {
    return bank.code === null ? bank.name : `${bank.name} · ${bank.code}`;
}

export function parseTaxCategories(payload: unknown): TaxCategory[] {
    const body = obj(payload);

    return (Array.isArray(body.categories) ? body.categories : []).map(parseCategory);
}

/** One transaction row — shared by the list and the duplicates view, so they read it identically. */
function parseTransactionRow(entry: unknown): TransactionSummary {
    const row = obj(entry);

    return {
        id: str(row, 'id'),
        accountId: str(row, 'account_id'),
        accountName: str(row, 'account_name'),
        accountKind: str(row, 'account_kind'),
        bookedAt: str(row, 'booked_at'),
        amountMinor: num(row, 'amount_minor'),
        currencyCode: str(row, 'currency_code'),
        description: str(row, 'description'),
        counterparty: nullableStr(row, 'counterparty'),
        classificationStatus: str(row, 'classification_status'),
        splits: (Array.isArray(row.splits) ? row.splits : []).map((splitEntry): TransactionSplit => {
            const split = obj(splitEntry);

            return {
                amountMinor: num(split, 'amount_minor'),
                categoryCode: str(split, 'category_code'),
                countryCode: nullableStr(split, 'country_code'),
            };
        }),
        source: str(row, 'source'),
        documentId: nullableStr(row, 'document_id'),
        bankId: nullableStr(row, 'bank_id'),
        bankName: nullableStr(row, 'bank_name'),
        tags: (Array.isArray(row.tags) ? row.tags : []).map((tagEntry): Tag => {
            const tag = obj(tagEntry);

            return { id: str(tag, 'id'), name: str(tag, 'name') };
        }),
        importId: nullableStr(row, 'import_id'),
    };
}

export function parseDuplicateGroups(payload: unknown): DuplicateGroup[] {
    const body = obj(payload);

    return (Array.isArray(body.groups) ? body.groups : []).map((entry): DuplicateGroup => {
        const group = obj(entry);

        return {
            survivorId: str(group, 'survivor_id'),
            transactions: (Array.isArray(group.transactions) ? group.transactions : []).map(parseTransactionRow),
        };
    });
}

export function parseTransactions(payload: unknown): TransactionPage {
    const body = obj(payload);

    return {
        transactions: (Array.isArray(body.transactions) ? body.transactions : []).map(parseTransactionRow),
        total: num(body, 'total'),
        page: num(body, 'page'),
        perPage: num(body, 'per_page'),
        categories: parseTaxCategories(payload),
    };
}

function parseHolding(payload: unknown): Holding {
    const row = obj(payload);

    return { amounts: money(row.amounts), converted: parseNetWorth(row.converted) };
}

export function parseWalletOverview(payload: unknown): WalletOverview {
    const body = obj(payload);
    const totals = obj(body.totals);
    const holdings = obj(totals.holdings);

    return {
        totals: {
            netWorth: parseNetWorth(totals.net_worth),
            holdings: {
                general: parseHolding(holdings.general),
                deposits: parseHolding(holdings.deposits),
                cash: parseHolding(holdings.cash),
                other: parseHolding(holdings.other),
                available: parseHolding(holdings.available),
            },
            depositCount: num(totals, 'deposit_count'),
            interestAtMaturity: parseHolding(totals.interest_at_maturity),
            interestReceived: parseHolding(totals.interest_received),
            owedToMe: parseHolding(totals.owed_to_me),
            recognisedRonMinor: nullableNum(totals, 'recognised_ron_minor'),
        },
        banks: (Array.isArray(body.banks) ? body.banks : []).map((entry): BankDeposits => {
            const row = obj(entry);

            return {
                bankId: nullableStr(row, 'bank_id'),
                name: nullableStr(row, 'name'),
                code: nullableStr(row, 'code'),
                depositCount: num(row, 'deposit_count'),
                principal: money(row.principal),
                interestAtMaturity: money(row.interest_at_maturity),
                converted: parseNetWorth(row.converted),
                nextMaturityAt: str(row, 'next_maturity_at'),
            };
        }),
        maturing: (Array.isArray(body.maturing) ? body.maturing : []).map(parseDeposit),
        maturingTotal: num(body, 'maturing_total'),
    };
}
