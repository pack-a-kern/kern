<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Money\Shares;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\DataTransferObjects\ConvertedTotal;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Models\DepositInterestEvent;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;
use Kern\TaxRomania\Wallet\Services\DepositMovements;
use Kern\TaxRomania\Wallet\Services\DepositTerm;
use Kern\TaxRomania\Wallet\Services\Fx\DisplayCurrencyConverter;
use Kern\TaxRomania\Wallet\Services\TagDirectory;
use RuntimeException;

/**
 * The read side of the wallet: what `/dashboard`, `/wallet/accounts` and `/wallet/transactions` show.
 *
 * Every model read here relies on `ScopedToTenant`'s global scope for tenancy — the scope applies
 * whether the author remembered or not (`docs/handoff/011-identity-tenancy.md`).
 *
 * The aggregates are the exception, and the reason this paragraph exists. They are read through the
 * plain query builder, because they carry no encrypted columns and are summed, never listed — and
 * the plain query builder has no global scope. A `DB::table('financial_transactions')` filtered only
 * by `account_id` sums every row pointing at those accounts, including one another subject managed
 * to write there. So a raw query over a table that has `tax_subject_id` filters on it by hand, as
 * `movedOutOfReach()` and `balancesByAccount()` do.
 */
final readonly class WalletQuery
{
    /** The groups "what you have" is split into, in the order the screen shows them. */
    private const string GENERAL = 'general';

    private const string DEPOSITS = 'deposits';

    private const string CASH = 'cash';

    private const string OTHER = 'other';

    /** The live value of bonds and shares held, passed into {@see totals()} — a holding, never a balance. */
    private const string INVESTMENTS = 'investments';

    /**
     * Money moved out of a tracked account to somewhere this application does not hold.
     *
     * A transfer has two ends and only one of them is recorded here: the taxpayer says the money
     * went to their own account at another bank, and nothing says what that account holds. Counting
     * only the end that is recorded loses the money — it leaves General and lands nowhere, so "what
     * you have" falls by an amount that was never spent.
     *
     * Spendable, unlike a deposit: it is in an account, just not one described here.
     */
    private const string INTERNAL = 'internal';



    /** The categories that say money was lent or paid back — `config/reference/tax_categories.php`. */
    private const string LOAN = 'LOAN';

    private const string LOAN_REPAYMENT = 'LOAN_REPAYMENT';

    /** Kinds in the order the accounts screen lists them. */
    private const array KIND_ORDER = ['TERM_DEPOSIT', 'CASH', 'BROKER', 'CRYPTO', 'OTHER', 'GENERAL'];

    public function __construct(
        private DisplayCurrencyConverter $converter,
        private TenantContext $tenant,
        private TagDirectory $tags,
        private CounterpartyDirectory $counterparties,
    ) {
    }

    /**
     * Sources with their balances, one per currency.
     *
     * ## Why a list and not a number
     *
     * A balance used to be a single figure labelled with the account's currency — which was wrong
     * the moment a source could hold more than one. The totals were summed with
     * `sum(amount_minor)` across every row regardless of denomination, so an eToro source holding
     * €2.000 and $1.100 reported **3.100 EUR**: a number that is not any quantity of anything, shown
     * with no warning. Grouping by currency is the fix, and a single-currency bank source simply
     * comes back with a list of one.
     *
     * ## Why it is derived rather than observed
     *
     * `account_balance_snapshots` is gone. A tax return never asks for a balance, so asking a person
     * to type one was work with no output; the useful snapshot is the closing figure a *statement*
     * carries, and there is no parser to read one yet. Until then the balance is the sum of what has
     * actually been recorded — honest, and labelled as derived.
     *
     * Every account, the investments ones a holding opened included: each screen narrows the list to what
     * it offers (`isStreamKind` on the client), and the Accounts screen leaves those out.
     *
     * @return list<AccountSummary>
     */
    public function accounts(): array
    {
        /** @var list<FinancialAccount> $accounts */
        $accounts = FinancialAccount::query()->with('depositTerms')->get()->all();

        if ($accounts === []) {
            return [];
        }

        $kinds = [];

        foreach ($accounts as $account) {
            $kinds[self::str($account->getKey())] = $account->kind;
        }

        $ids = array_keys($kinds);
        $balances = $this->balancesByAccount($kinds)['balances'];
        // One query each for the whole page rather than per deposit row: the rate and the interest
        // totals are all simple aggregates keyed by account.
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $rates = $this->currentRates($ids, $today);
        $interest = $this->interestAtMaturity($ids);
        $received = $this->interestReceivedFor($ids);

        $summaries = array_map(
            function (FinancialAccount $account) use ($balances, $rates, $interest, $received, $today): AccountSummary {
                $id = self::str($account->getKey());

                return new AccountSummary(
                    id: $id,
                    // Decrypted by the `Encrypted` cast on read; it never leaves this process in
                    // any other form.
                    displayName: self::str($account->display_name_ciphertext),
                    kind: $account->kind->value,
                    // A default for the form only — a source may legitimately have none.
                    currencyCode: $account->currency_code,
                    balances: self::balanceList($balances[$id] ?? []),
                    source: $account->source->value,
                    heldAt: $account->held_at?->value,
                    deposit: $this->depositFacts(
                        $account,
                        $rates[$id] ?? null,
                        $interest[$id] ?? null,
                        $received[$id] ?? null,
                        $today,
                    ),
                );
            },
            $accounts,
        );

        usort($summaries, self::listOrder(...));

        return $summaries;
    }

    /**
     * The deposit half of an account row, or null when the account is not one.
     */
    private function depositFacts(
        FinancialAccount $account,
        ?string $annualRate,
        ?int $interestAtMaturity,
        ?int $interestReceived,
        CarbonImmutable $today,
    ): ?DepositFacts {
        $terms = $account->depositTerms;

        if ($terms === null) {
            return null;
        }

        $maturity = $terms->maturity_at->toImmutable()->startOfDay();
        $opened = $terms->starts_at->toImmutable()->startOfDay();

        return new DepositFacts(
            currencyCode: self::str($terms->currency_code),
            startsAt: $opened->toDateString(),
            principalMinor: $terms->principal_minor,
            maturityAt: $maturity->toDateString(),
            termMonths: DepositTerm::months($opened, $maturity),
            annualRate: $annualRate,
            rateType: $terms->rate_type->value,
            interestMethod: $terms->interest_method->value,
            paymentFrequency: $terms->payment_frequency->value,
            dayCountConvention: $terms->day_count_convention->value,
            closed: $terms->closed_at !== null,
            // Posted at all is what locks the terms; how much is what the screen shows.
            interestPosted: $interestReceived !== null,
            interestReceivedMinor: $interestReceived ?? 0,
            fundingAccountId: $terms->funding_account_id,
            bankId: $terms->bank_id,
            interestAtMaturityMinor: $interestAtMaturity,
            totalDays: (int) $opened->diffInDays($maturity, absolute: false),
            // Negative once matured, which the screen renders as "matured" rather than a countdown.
            daysToMaturity: (int) $today->diffInDays($maturity, absolute: false),
        );
    }

    /**
     * Deposits, soonest maturity first.
     *
     * @return list<DepositSummary>
     */
    public function deposits(bool $includeClosed = true): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        // Deposits are accounts now, distinguished by having terms. Joined through the relation
        // rather than queried as their own table — one account per account.
        $query = FinancialAccount::query()
            ->whereHas('depositTerms')
            ->with('depositTerms')
            ->join('deposit_terms', 'deposit_terms.account_id', '=', 'financial_accounts.id')
            ->orderBy('deposit_terms.maturity_at')
            ->select('financial_accounts.*');

        // Closed, not "past its maturity": a deposit between its maturity and the nightly settlement
        // has not moved its money yet, and dropping it early made the total dip with nothing moved.
        if (! $includeClosed) {
            $query->whereNull('deposit_terms.closed_at');
        }

        /** @var list<FinancialAccount> $deposits */
        $deposits = $query->get()->all();

        if ($deposits === []) {
            return [];
        }

        $ids = array_map(static fn (FinancialAccount $deposit): string => self::str($deposit->getKey()), $deposits);
        $rates = $this->currentRates($ids, $today);
        $interest = $this->interestAtMaturity($ids);

        return array_map(
            function (FinancialAccount $deposit) use ($rates, $interest, $today): DepositSummary {
                $id = self::str($deposit->getKey());
                $terms = $deposit->depositTerms;

                if ($terms === null) {
                    // Unreachable: the query selects only accounts that have terms. Restated so the
                    // reads below are provably non-null rather than assumed to be.
                    throw new RuntimeException("Account {$id} was selected as a deposit but has no terms.");
                }

                // Taken from the model's own cast, not stringified first: `starts_at` and
                // `maturity_at` are cast to Carbon, and routing a Carbon through a string helper
                // yielded an empty string that `parse()` read as *now* — every deposit reported
                // zero days to maturity. Caught by a test, never visible in the type system.
                $maturity = $terms->maturity_at->toImmutable()->startOfDay();
                $opened = $terms->starts_at->toImmutable()->startOfDay();

                return new DepositSummary(
                    id: $id,
                    // Decrypted by the model's cast on read, like everywhere else.
                    displayName: self::str($deposit->display_name_ciphertext),
                    currencyCode: self::str($terms->currency_code),
                    principalMinor: $terms->principal_minor,
                    annualRate: $rates[$id] ?? null,
                    rateType: $terms->rate_type->value,
                    paymentFrequency: $terms->payment_frequency->value,
                    openedAt: $opened->toDateString(),
                    maturityAt: $maturity->toDateString(),
                    closed: $terms->closed_at !== null,
                    interestAtMaturityMinor: $interest[$id] ?? null,
                    // The term as bought, in days — fixed at opening, and the span the interest
                    // above was actually calculated over.
                    totalDays: (int) $opened->diffInDays($maturity, absolute: false),
                    // Negative for a matured deposit, which the screen renders as "matured" rather
                    // than as a countdown.
                    daysToMaturity: (int) $today->diffInDays($maturity, absolute: false),
                    bankId: $terms->bank_id,
                );
            },
            $deposits,
        );
    }

    /**
     * Transactions, newest first, optionally for one account.
     *
     * Capped rather than paged: this screen is for looking at recent activity, and a person with
     * thousands of transactions needs a statement view with filters, not a longer scroll. The cap
     * is visible in the response so the screen can say it is showing a window.
     *
     * @return list<TransactionSummary>
     */
    /**
     * The banks the rows in front of this person are actually at — what the bank filter offers.
     *
     * The register holds forty-six Romanian banks; a person's money sits at two or three. A filter
     * offering the register is forty-odd choices that each return nothing.
     *
     * @param  array{account_id?: ?string, bank_id?: ?string, tag_id?: ?string, counterparty?: ?string, classification?: ?string, category_code?: ?string, direction?: ?string, from?: ?string, to?: ?string}  $filters
     * @return list<string>
     */
    public function bankIdsInUse(array $filters = []): array
    {
        return $this->reachable('bank_id', $filters);
    }

    /**
     * The sources those rows are on — what the account filter offers.
     *
     * Same problem as the banks, found the same way and easier to hit: this filter offered every
     * account, and two kinds of account can hold nothing it would list. A `CASH` holding is a
     * declared value rather than a stream of payments, so the list excludes the whole account and
     * choosing it always answered "no transactions". A `TERM_DEPOSIT` answers the same until its
     * first interest posting, and then stops — which is the case a rule about kinds would miss and
     * this one gets for free.
     *
     * @param  array{account_id?: ?string, bank_id?: ?string, tag_id?: ?string, counterparty?: ?string, classification?: ?string, category_code?: ?string, direction?: ?string, from?: ?string, to?: ?string}  $filters
     * @return list<string>
     */
    public function accountIdsInUse(array $filters = []): array
    {
        return $this->reachable('account_id', $filters);
    }

    /**
     * The values of one column still reachable from where the filters have put this person.
     *
     * ## Narrowed by the other filters, never by its own
     *
     * Filter by a tag and only the banks that tag appears at are offered: the filters describe one
     * view of the ledger together, so each should say what is reachable from where the person now is.
     *
     * Its own filter is the exception, and it is the whole reason this takes the filters apart
     * rather than passing them through. A list narrowed by the bank already chosen holds exactly
     * that bank, so changing banks means clearing the filter and choosing again instead of just
     * choosing.
     *
     * The tenant scope on the model does the scoping, as everywhere else in this class.
     *
     * @param  'account_id'|'bank_id'  $column
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function reachable(string $column, array $filters): array
    {
        unset($filters[$column]);

        $query = $this->listedTransactions()->whereNotNull($column);

        $this->applyTransactionFilters($query, $filters);

        /** @var list<string> $values */
        $values = $query->distinct()->pluck($column)->all();

        return $values;
    }

    /**
     * The transaction list, narrowed and paged by the server.
     *
     * ## Why the server pages this
     *
     * It used to return one capped window — the newest 100, at most 250 — which the browser then
     * filtered. That works while a person types their transactions in by hand. A single imported
     * statement is several hundred rows, so a filter applied to a window would quietly answer "no
     * transactions in March" about a March that simply fell off the end of the window.
     *
     * ## What is left out, always
     *
     * A cash holding's declared value and a deposit's own funding transfer are not payments anyone
     * made; they exist so the balances add up, and listing them makes a person hunt for the rows
     * that are actually theirs.
     *
     * @param  array{account_id?: ?string, bank_id?: ?string, tag_id?: ?string, counterparty?: ?string, classification?: ?string, category_code?: ?string, direction?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array{transactions: list<TransactionSummary>, total: int}
     */
    public function transactions(array $filters, int $page, int $perPage): array
    {
        $query = $this->listedTransactions();

        $this->applyTransactionFilters($query, $filters);

        $total = (clone $query)->count();

        /** @var list<FinancialTransaction> $transactions */
        $transactions = $query
            // `id` breaks the tie: a UUIDv7 is ordered by the moment the row was written, so rows
            // booked on the same day keep a stable order and paging cannot repeat or skip one.
            ->orderByDesc('booked_at')
            ->orderByDesc('id')
            ->forPage(max(1, $page), $perPage)
            ->get()
            ->all();

        return ['transactions' => $this->summarise($transactions), 'total' => $total];
    }

    /**
     * Every transaction this screen is willing to show, before any filter.
     *
     * Shared so the list and the bank filter agree about what exists by construction rather than by
     * coincidence. Nothing writes an excluded row with a bank today — a cash holding's declared value
     * and a deposit's funding transfer both carry none — so the filter reading the table directly
     * happened to give the same answer. It would stop the day one of them gained a bank, and the
     * symptom would be a filter offering a choice that returns nothing, which is the one thing it
     * exists to prevent.
     *
     * @return \Illuminate\Database\Eloquent\Builder<FinancialTransaction>
     */
    private function listedTransactions(): Builder
    {
        return FinancialTransaction::query()
            ->whereNotIn('account_id', FinancialAccount::query()->where('kind', AccountKind::CASH->value)->select('id'))
            ->whereNot(static function ($query): void {
                $query->where('source', RecordSource::SYSTEM->value)
                    ->whereExists(static function ($splits): void {
                        $splits->from('transaction_splits')
                            ->whereColumn('transaction_splits.transaction_id', 'financial_transactions.id')
                            ->where('category_code', DepositMovements::TRANSFER);
                    });
            });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<FinancialTransaction>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyTransactionFilters(mixed $query, array $filters): void
    {
        $value = static fn (string $key): ?string => is_string($filters[$key] ?? null) && $filters[$key] !== ''
            ? $filters[$key]
            : null;

        if (($accountId = $value('account_id')) !== null) {
            $query->where('account_id', $accountId);
        }

        if (($bankId = $value('bank_id')) !== null) {
            $query->where('bank_id', $bankId);
        }

        /*
         * Typed into the search box above the ledger.
         *
         * The name is encrypted and the index beside it is a hash of the whole name, so neither
         * column can be matched against a fragment — `LIKE '%mega%'` has nothing to compare, and
         * `HMAC(key, 'mega')` is unrelated to the hash of "Mega Image". So the directory is decrypted
         * and matched in PHP, and what comes out of that is a list of hashes this query can use.
         *
         * Every match, not one of them. A person typing "lukoil" means the fuel they bought, and
         * their bank wrote that name five ways — filtering by one of the five answers with nine rows
         * out of thirty-five, which looks like an answer and is not.
         *
         * Two letters before it matches anything: one letter matches most of a directory, and the
         * decrypting is the cost of the whole operation.
         */
        if (($counterparty = $value('counterparty')) !== null && mb_strlen(trim($counterparty)) >= 2) {
            $indexes = [];

            foreach ($this->counterparties->search($counterparty, PHP_INT_MAX) as $match) {
                array_push($indexes, ...$match['indexes']);
            }

            // Nothing matched is not the same as no filter: a search for a name this person has never
            // paid should answer with an empty ledger, not with all of it.
            $query->whereIn('counterparty_blind_index', $indexes === [] ? [''] : $indexes);
        }

        if (($tagId = $value('tag_id')) !== null) {
            // `whereExists` rather than a join: a transaction can carry several tags, and a join
            // would return it once per tag and make the page size mean nothing.
            $query->whereExists(static function ($tags) use ($tagId): void {
                $tags->from('transaction_tags')
                    ->whereColumn('transaction_tags.transaction_id', 'financial_transactions.id')
                    ->where('tag_id', $tagId);
            });
        }

        if (($from = $value('from')) !== null) {
            $query->whereDate('booked_at', '>=', $from);
        }

        if (($to = $value('to')) !== null) {
            $query->whereDate('booked_at', '<=', $to);
        }

        if (($direction = $value('direction')) !== null) {
            $query->where('amount_minor', $direction === MoneyDirection::OUT->value ? '<' : '>', 0);
        }

        if (($category = $value('category_code')) !== null) {
            // On the splits, because a category is a property of a share of a transaction rather than
            // of the transaction: one payment can be half rent and half spending, and it belongs in
            // both answers. `whereExists` rather than a join for that reason — a join would return
            // such a row twice and quietly inflate every count on the screen.
            $query->whereExists(static function ($splits) use ($category): void {
                $splits->from('transaction_splits')
                    ->whereColumn('transaction_splits.transaction_id', 'financial_transactions.id')
                    ->where('transaction_splits.category_code', $category);
            });
        }

        $classification = $value('classification');

        if ($classification === TransactionClassificationStatus::CLASSIFIED->value) {
            $query->where('classification_status', TransactionClassificationStatus::CLASSIFIED->value);
        }

        if ($classification === TransactionClassificationStatus::UNCLASSIFIED->value) {
            // The worklist: money that came in with no answer yet. What went out is spending unless
            // someone says otherwise, and never becomes a declared figure.
            $query->where('classification_status', TransactionClassificationStatus::UNCLASSIFIED->value)
                ->where('amount_minor', '>', 0);
        }
    }

    /**
     * Rows as the transactions screen shows them.
     *
     * Public because the duplicates view shows the same rows in groups, and a second way of turning
     * a row into what the screen reads — account names, decrypted counterparty, splits — would be a
     * second place for the two views to disagree about what one transaction looks like.
     *
     * @param  list<FinancialTransaction>  $transactions
     * @return list<TransactionSummary>
     */
    public function summarise(array $transactions): array
    {
        if ($transactions === []) {
            return [];
        }

        // One lookup for the accounts these rows name rather than a relation load per row: the display
        // name is encrypted, so each read costs a decrypt, and a list repeats the same handful. Only the
        // name and the kind — not `accounts()`, which works out every balance to be thrown away here.
        $names = [];

        $kinds = [];
        $places = [];

        $accountIds = array_values(array_unique(array_map(
            static fn (FinancialTransaction $transaction): string => self::str($transaction->account_id),
            $transactions,
        )));

        foreach (FinancialAccount::query()->whereKey($accountIds)->get(['id', 'tax_subject_id', 'kind', 'held_at', 'display_name_ciphertext']) as $account) {
            $id = self::str($account->getKey());
            $names[$id] = self::str($account->display_name_ciphertext);
            $kinds[$id] = $account->kind->value;
            $places[$id] = $account->held_at?->value;
        }

        $ids = array_map(
            static fn (FinancialTransaction $transaction): string => self::str($transaction->getKey()),
            $transactions,
        );

        $splits = $this->splitsByTransaction($ids);
        $banks = $this->bankNames($transactions);
        $tags = $this->tagsByTransaction($ids);

        return array_map(
            function (FinancialTransaction $transaction) use ($names, $kinds, $places, $splits, $banks, $tags): TransactionSummary {
                $accountId = self::str($transaction->account_id);

                return new TransactionSummary(
                    id: self::str($transaction->getKey()),
                    accountId: $accountId,
                    accountName: $names[$accountId] ?? '',
                    accountKind: $kinds[$accountId] ?? '',
                    bookedAt: $transaction->booked_at->toDateString(),
                    amountMinor: (int) $transaction->amount_minor,
                    currencyCode: self::str($transaction->currency_code),
                    description: self::str($transaction->description_ciphertext),
                    counterparty: ($counterparty = self::str($transaction->counterparty_ciphertext)) === ''
                        ? null
                        : $counterparty,
                    classificationStatus: $transaction->classification_status->value,
                    splits: $splits[self::str($transaction->getKey())] ?? [],
                    source: $transaction->source->value,
                    documentId: $transaction->document_id,
                    importId: $transaction->import_id,
                    bankId: $transaction->bank_id,
                    bankName: $transaction->bank_id === null ? null : ($banks[$transaction->bank_id] ?? null),
                    tags: $tags[self::str($transaction->getKey())] ?? [],
                    accountHeldAt: $places[$accountId] ?? null,
                );
            },
            $transactions,
        );
    }

    /**
     * What the taxpayer has, where it is, and what their deposits earn — the overview's figures.
     *
     * ## Where the money is
     *
     * Four groups, each per currency and each also converted into the display currency:
     *
     * - **General** and **other accounts** (broker, crypto, other): what has been recorded in them,
     *   below zero where the records start after money was already there — see {@see heldInAccounts()}.
     * - **Deposits**: the principal of every running deposit, plus interest paid into one and not yet
     *   settled back out.
     * - **Cash**: each holding's declared value.
 *
 * **Available** is General and cash together — what can be spent today — returned beside the four.
     *
     * **Owed to me** is money lent and not yet paid back. It is beside the groups, never in them: the
     * money has left the taxpayer's accounts, and a promise to return it is not a balance.
     *
     * "What you have" is the four added together. Converted at the latest BNR rate: a dashboard
     * indication that comes back with the rates and dates it used, never a figure a tax is computed
     * from (`BLUEPRINT.md` §7.4).
     *
     * @param  list<DepositSummary>  $running  the running deposits, as {@see deposits()} returns them
     * @param  array<string, int>  $holdings  the live value of bonds and shares per currency, in minor units
     * @return array<string, mixed>
     */
    public function totals(array $running, array $holdings = []): array
    {
        ['held' => $held, 'short_from' => $shortFrom] = $this->heldInAccounts();
        // The live value of bonds and shares, read by the caller and handed in — money that moved into
        // an asset and is still the taxpayer's, like a deposit's principal. Valued, never a balance.
        $held[self::INVESTMENTS] = $holdings;
        $expected = [];

        foreach ($running as $deposit) {
            $currency = $deposit->currencyCode;
            $held[self::DEPOSITS][$currency] = ($held[self::DEPOSITS][$currency] ?? 0) + $deposit->principalMinor;

            if ($deposit->interestAtMaturityMinor !== null) {
                $expected[$currency] = ($expected[$currency] ?? 0) + $deposit->interestAtMaturityMinor;
            }
        }

        $everything = [];

        foreach ($held as $amounts) {
            foreach ($amounts as $currency => $amount) {
                $everything[$currency] = ($everything[$currency] ?? 0) + $amount;
            }
        }

        // What can be spent today: General, cash, and what was moved to the taxpayer's own accounts
        // elsewhere — that money is in an account, just not one described here. Deposits are locked
        // until they mature, and broker or crypto accounts are invested rather than spendable, so
        // neither is in it. Added per currency and converted once, so it rounds the way every other
        // figure here does.
        $available = $held[self::GENERAL];

        foreach ([self::CASH, self::INTERNAL] as $group) {
            foreach ($held[$group] as $currency => $amount) {
                $available[$currency] = ($available[$currency] ?? 0) + $amount;
            }
        }

        $display = $this->displayCurrency();

        return [
            'net_worth' => $this->converter->convert($everything, $display)->toArray(),
            'breakdown' => $this->breakdown($held, $display),
            'holdings' => [
                ...array_map(fn (array $amounts): array => $this->converted($amounts, $display), $held),
                'available' => $this->converted($available, $display),
            ],
            'deposit_count' => count($running),
            // A forecast for the running deposits: gross, over each whole term.
            'interest_at_maturity' => $this->converted($expected, $display),
            'interest_received' => $this->converted($this->interestReceived(), $display),
            'owed_to_me' => $this->converted($this->owedToMe(), $display),
            // The first day recorded in an account that ends below zero: what it held before then is
            // not imported, so its balance — and the total — read low by that much. Null when every
            // account adds up.
            'records_short_from' => $shortFrom,
            // What a tax return would actually see. `ron_equivalent_minor` is written by
            // `DepositInterestFxAnnotator` at the BNR rate for the accrual date, with the source
            // document version recorded beside it — so this one may legitimately be a single number.
        ];
    }

    /**
     * Where the money is, as the dashboard draws it: deposits, General with what was moved to the
     * taxpayer's own accounts elsewhere — a transfer to one's own account at another bank is not a
     * different kind of holding — cash, and other accounts once there is one; each with its share of
     * them all, in the display currency. A currency with no rate is left out of a part and the whole
     * alike, rather than drawn at a size nobody computed.
     *
     * @param  array<string, array<string, int>>  $held
     * @return list<array{group: string, amounts: array<string, int>, converted: array<string, mixed>, share: string}>
     */
    private function breakdown(array $held, string $display): array
    {
        $general = $held[self::GENERAL];

        foreach ($held[self::INTERNAL] as $currency => $amount) {
            $general[$currency] = ($general[$currency] ?? 0) + $amount;
        }

        $groups = [self::DEPOSITS => $held[self::DEPOSITS], self::GENERAL => $general, self::CASH => $held[self::CASH]];

        // Most taxpayers have no broker or exchange account: a permanent zero would answer nothing asked.
        if ($held[self::OTHER] !== []) {
            $groups[self::OTHER] = $held[self::OTHER];
        }

        // Bonds and shares, once there are any — their live value, the same way deposits show.
        if (($held[self::INVESTMENTS] ?? []) !== []) {
            $groups[self::INVESTMENTS] = $held[self::INVESTMENTS];
        }

        /** @var array<string, array{amounts: array<string, int>, total: ConvertedTotal}> $parts */
        $parts = [];

        foreach ($groups as $group => $amounts) {
            ksort($amounts);
            $parts[$group] = ['amounts' => $amounts, 'total' => $this->converter->convert($amounts, $display)];
        }

        $shares = Shares::of(array_map(static fn (array $part): int => $part['total']->amountMinor, $parts));
        $breakdown = [];

        foreach ($parts as $group => $part) {
            $breakdown[] = ['group' => $group, 'amounts' => $part['amounts'], 'converted' => $part['total']->toArray(), 'share' => $shares[$group]];
        }

        return $breakdown;
    }

    /**
     * Running deposits grouped by the bank they are at, largest first.
     *
     * Bank names come from `ext_banks` through the query builder, never the Banks module's model —
     * public reference data read at the table, the crossing `docs/AGENTS.md` §6 allows. A deposit
     * recorded before banks were imported has no bank, and is grouped on its own under no name.
     *
     * @param  list<DepositSummary>  $running  soonest maturity first, as {@see deposits()} returns them
     * @return list<BankDeposits>
     */
    public function depositsByBank(array $running): array
    {
        $groups = [];

        foreach ($running as $deposit) {
            $key = $deposit->bankId ?? '';
            $currency = $deposit->currencyCode;
            // `$running` is ordered by maturity, so the first deposit seen at a bank matures next.
            $group = $groups[$key] ?? ['count' => 0, 'principal' => [], 'interest' => [], 'next' => $deposit->maturityAt];

            $group['count']++;
            $group['principal'][$currency] = ($group['principal'][$currency] ?? 0) + $deposit->principalMinor;

            if ($deposit->interestAtMaturityMinor !== null) {
                $group['interest'][$currency] = ($group['interest'][$currency] ?? 0) + $deposit->interestAtMaturityMinor;
            }

            $groups[$key] = $group;
        }

        $banks = [];

        foreach (DB::table('ext_banks')->whereIn('id', array_filter(array_keys($groups), static fn (string $id): bool => $id !== ''))->get(['id', 'name', 'bic']) as $row) {
            $banks[self::str($row->id)] = ['name' => self::str($row->name), 'bic' => self::str($row->bic)];
        }

        $display = $this->displayCurrency();
        $converted = array_map(fn (array $group): ConvertedTotal => $this->converter->convert($group['principal'], $display), $groups);
        $shares = Shares::of(array_map(static fn (ConvertedTotal $total): int => $total->amountMinor, $converted));
        $result = [];

        foreach ($groups as $bankId => $group) {
            $bank = $banks[$bankId] ?? null;

            ksort($group['principal']);
            ksort($group['interest']);

            $result[] = new BankDeposits(
                bankId: $bankId === '' ? null : $bankId,
                name: $bank['name'] ?? null,
                code: $bank === null || $bank['bic'] === '' ? null : substr($bank['bic'], 0, 4),
                depositCount: $group['count'],
                principal: $group['principal'],
                interestAtMaturity: $group['interest'],
                converted: $converted[$bankId],
                nextMaturityAt: $group['next'],
                share: $shares[$bankId],
            );
        }

        usort($result, static fn (BankDeposits $a, BankDeposits $b): int => $b->converted->amountMinor <=> $a->converted->amountMinor);

        return $result;
    }

    /**
     * What is recorded in each kind of account, per currency — every group but the deposits' principal.
     *
     * An account is the sum of what was recorded in it ({@see balancesByAccount()}), so it can be below
     * zero: a statement for 2023 that spends more than it brings in says so, and importing 2022 makes
     * it right by itself. Nothing is guessed to hide it — the account says where its records start.
     *
     * @return array{held: array{general: array<string, int>, deposits: array<string, int>, cash: array<string, int>, other: array<string, int>, internal: array<string, int>}, short_from: string|null}
     */
    private function heldInAccounts(): array
    {
        $held = [
            self::GENERAL => [],
            self::DEPOSITS => [],
            self::CASH => [],
            self::OTHER => [],
            self::INTERNAL => $this->movedOutOfReach(),
        ];
        $kinds = [];

        foreach (FinancialAccount::query()->get(['id', 'kind']) as $account) {
            $kinds[self::str($account->getKey())] = $account->kind;
        }

        $derived = $this->balancesByAccount($kinds);

        foreach ($derived['balances'] as $accountId => $byCurrency) {
            $group = match ($kinds[$accountId]) {
                AccountKind::GENERAL => self::GENERAL,
                AccountKind::TERM_DEPOSIT => self::DEPOSITS,
                AccountKind::CASH => self::CASH,
                // What an investments account holds in money — coupons, dividends, repayments, sales — not
                // its bonds and shares, whose value is the holdings' and is never a balance.
                AccountKind::INVESTMENTS, AccountKind::BROKER, AccountKind::CRYPTO, AccountKind::OTHER => self::OTHER,
            };

            foreach ($byCurrency as $currency => $amount) {
                $held[$group][$currency] = ($held[$group][$currency] ?? 0) + $amount;
            }
        }

        // A deposit funded past what the account recorded was lifted back as money that must have been
        // there ({@see balancesByAccount()}). Where that same currency also shows money moved to the
        // taxpayer's other bank, the two can be one and the same — the records cannot show the money
        // coming back to fund the deposit — so counting the lift and the full transfer-out would double
        // it. Draw the funded lift down from the money out of reach, never below zero: what the transfer
        // out can no longer account for is already the deposit's principal.
        foreach ($derived['funding_lift'] as $currency => $lifted) {
            $outOfReach = $held[self::INTERNAL][$currency] ?? 0;

            if ($outOfReach > 0) {
                $held[self::INTERNAL][$currency] = $outOfReach - min($lifted, $outOfReach);
            }
        }

        return ['held' => $held, 'short_from' => $derived['short_from']];
    }

    /**
     * What left the taxpayer's tracked accounts by transfer and has not come back — money now sitting
     * in an account this installation does not hold, which is still theirs.
     *
     * The figure is the transfers out, less the transfers back in, per currency. Both legs are
     * written as plain transactions, so a transfer in has *already* raised the balance of the account
     * it landed in; counting the full out on top of that would hold the returned money twice, once in
     * the balance and once here. Netting the two leaves only what genuinely left and stayed out. A
     * currency where more came in than went out contributes nothing — the surplus is in a balance, not
     * out of reach — so each currency is floored at zero rather than allowed to go negative and eat
     * into the real holdings. Reports drop every transfer outright for the same reason a transfer is
     * neither income nor spending (`WalletReports::base()`); a balance keeps the net that left.
     *
     * A deposit's own funding is excluded from the out side by the link the deposit keeps to it. That
     * money is in the deposits group as the deposit's principal, so counting it here as well would
     * double it — which is the whole reason the exclusion is the link and not the category: a funding
     * transfer and a transfer to another bank are written identically, and only the link tells them
     * apart.
     *
     * Not `TransactionSplit` and `DepositTerms` models but their tables, like every other aggregate
     * in this class: these are sums over rows, and hydrating them would buy nothing.
     *
     * @return array<string, int> positive amounts, per currency
     */
    private function movedOutOfReach(): array
    {
        $isTransfer = static function ($splits): void {
            $splits->from('transaction_splits')
                ->whereColumn('transaction_splits.transaction_id', 'financial_transactions.id')
                ->where('transaction_splits.category_code', DepositMovements::TRANSFER);
        };

        $outs = DB::table('financial_transactions')
            ->where('financial_transactions.tax_subject_id', $this->tenant->currentTenantId())
            ->where('financial_transactions.amount_minor', '<', 0)
            ->whereExists($isTransfer)
            ->whereNotExists(static function ($terms): void {
                $terms->from('deposit_terms')
                    ->whereColumn('deposit_terms.funding_transaction_id', 'financial_transactions.id');
            })
            ->groupBy('financial_transactions.currency_code')
            ->get(['financial_transactions.currency_code', DB::raw('sum(-amount_minor) as total')]);

        $ins = DB::table('financial_transactions')
            ->where('financial_transactions.tax_subject_id', $this->tenant->currentTenantId())
            ->where('financial_transactions.amount_minor', '>', 0)
            ->whereExists($isTransfer)
            ->groupBy('financial_transactions.currency_code')
            ->get(['financial_transactions.currency_code', DB::raw('sum(amount_minor) as total')]);

        $backIn = [];

        foreach ($ins as $row) {
            $backIn[self::str($row->currency_code)] = (int) $row->total;
        }

        $held = [];

        foreach ($outs as $row) {
            $currency = self::str($row->currency_code);
            $net = (int) $row->total - ($backIn[$currency] ?? 0);

            if ($net > 0) {
                $held[$currency] = $net;
            }
        }

        return $held;
    }

    /**
     * Amounts per currency, beside the same amounts in the display currency.
     *
     * @param  array<string, int>  $amounts
     * @return array{amounts: array<string, int>, converted: array<string, mixed>}
     */
    private function converted(array $amounts, string $display): array
    {
        ksort($amounts);

        return ['amounts' => $amounts, 'converted' => $this->converter->convert($amounts, $display)->toArray()];
    }

    /**
     * Interest already paid, net of withholding, per currency: the periods that became transactions.
     *
     * @return array<string, int>
     */
    private function interestReceived(): array
    {
        $received = [];

        foreach (
            DepositInterestEvent::query()
                ->whereNotNull('transaction_id')
                ->groupBy('currency_code')
                ->selectRaw('currency_code, sum(net_interest_minor) as total')
                ->toBase()
                ->get() as $row
        ) {
            $received[self::str($row->currency_code)] = (int) $row->total;
        }

        return $received;
    }

    /**
     * Money lent and not yet paid back, per currency: loans that left an account, less repayments that
     * came in.
     *
     * A loan *received* runs the other way — money in, repaid with money out — and is not in it. A
     * currency repaid in full, or repaid more than was lent, shows nothing rather than a negative
     * debt: what a borrower paid over the loan is not something they are owed back.
     *
     * @return array<string, int>
     */
    private function owedToMe(): array
    {
        $owed = [];

        foreach (
            TransactionSplit::query()
                ->join('financial_transactions', 'financial_transactions.id', '=', 'transaction_splits.transaction_id')
                ->where(static function ($query): void {
                    $query
                        ->where(static function ($lent): void {
                            $lent->where('transaction_splits.category_code', self::LOAN)
                                ->where('transaction_splits.amount_minor', '<', 0);
                        })
                        ->orWhere(static function ($repaid): void {
                            $repaid->where('transaction_splits.category_code', self::LOAN_REPAYMENT)
                                ->where('transaction_splits.amount_minor', '>', 0);
                        });
                })
                ->groupBy('financial_transactions.currency_code')
                ->selectRaw('financial_transactions.currency_code as currency_code, sum(transaction_splits.amount_minor) as total')
                ->toBase()
                ->get() as $row
        ) {
            $outstanding = -(int) $row->total;

            if ($outstanding > 0) {
                $owed[self::str($row->currency_code)] = $outstanding;
            }
        }

        return $owed;
    }

    private function displayCurrency(): string
    {
        return TaxSubject::displayCurrencyOf($this->tenant->currentTenantId());
    }


    /**
     * The bank name behind each `bank_id` on the page, in one query.
     *
     * Read at the table rather than through the Banks module's model: `ext_banks` is public
     * reference data, and this is the crossing `docs/AGENTS.md` §6 allows — the same one
     * {@see depositsByBank()} already makes.
     *
     * @param  list<FinancialTransaction>  $transactions
     * @return array<string, string>
     */
    private function bankNames(array $transactions): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (FinancialTransaction $transaction): ?string => $transaction->bank_id,
            $transactions,
        ))));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('ext_banks')->whereIn('id', $ids)->get(['id', 'name']) as $row) {
            $names[self::str($row->id)] = self::str($row->name);
        }

        return $names;
    }

    /**
     * Every transaction's tags, in one query plus one decrypt of this taxpayer's tag list.
     *
     * The names are encrypted, so they cannot be joined in SQL — but a person has a handful of tags
     * and hundreds of transactions, so the list is decrypted once and the link table read once,
     * rather than a decrypt per row.
     *
     * @param  list<string>  $transactionIds
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function tagsByTransaction(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $names = $this->tags->all();
        $tags = [];

        foreach (
            DB::table('transaction_tags')
                ->whereIn('transaction_id', $transactionIds)
                ->get(['transaction_id', 'tag_id']) as $row
        ) {
            $tagId = self::str($row->tag_id);
            $name = $names[$tagId] ?? null;

            if ($name !== null) {
                $tags[self::str($row->transaction_id)][] = ['id' => $tagId, 'name' => $name];
            }
        }

        return $tags;
    }

    /**
     * Every transaction's splits, in one query.
     *
     * One query for the page rather than a relation load per row: the transaction list is the
     * classification worklist, so each row shows what it was said to be, and a query per row is
     * how that screen becomes unusable at fifty of them. `transaction_splits` carries no encrypted
     * column, so the query builder is enough.
     *
     * @param  list<string>  $transactionIds
     * @return array<string, list<array{amount_minor: int, category_code: string, country_code: string|null}>>
     */
    private function splitsByTransaction(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = DB::table('transaction_splits')
            ->whereIn('transaction_id', $transactionIds)
            ->get(['transaction_id', 'amount_minor', 'category_code', 'country_code']);

        $byTransaction = [];

        foreach ($rows as $row) {
            $byTransaction[self::str($row->transaction_id)][] = [
                'amount_minor' => (int) $row->amount_minor,
                'category_code' => self::str($row->category_code),
                'country_code' => $row->country_code === null ? null : self::str($row->country_code),
            ];
        }

        return $byTransaction;
    }

    /**
     * Each account's balance per currency, from what has been recorded in it.
     *
     * Grouped by *currency*, never summed across: an account holding euros and dollars reports two
     * figures, because their numeric sum is not any quantity of anything. A currency whose movements
     * cancel to zero is kept — "this source had dollars and they are gone" is a different statement
     * from "this source never had dollars".
     *
     * ## Money sent out proves the money was there
     *
     * A plain sum, with one exception. Money that leaves an account but stays the taxpayer's — a
     * deposit opened from it ({@see \Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount}), or a
     * transfer to their own account at another bank ({@see movedOutOfReach()}) — is itself the proof
     * the money existed: opening a 100 000 lei deposit, or sending 100 000 lei to another bank, with
     * no income recorded must not leave the account at minus 100 000 and the total at nothing. So,
     * read day by day, a day whose funding or own-transfer takes the account below zero is lifted
     * back — by what left that way that day at most, and never by anything else. Ordinary spending
     * beyond the records stays below zero, because nothing proves what paid for it.
     *
     * The funding share of the lift is reported back as `funding_lift` so the caller can net it
     * against {@see movedOutOfReach()}: that money is now a deposit's principal, and the same balance
     * cannot also still be sitting in the other bank. The transfer share needs no such netting — it
     * is exactly what put the money in the other bank, which that aggregate already counts.
     *
     * The lift is part of the running balance, so the money proven there is spent from afterwards
     * like any other — a deposit funded, settled back and 15 000 lei spent leaves 85 000 lei — and it
     * is worked out again on every read, so recording the income that really paid for it makes it
     * vanish by itself. By day rather than by row, because the order of one day's movements is not
     * known — a statement lists them in no particular order.
     *
     * `short_from` is the first day recorded in a stream account that ends below zero, the earliest
     * if several do; null when none does.
     *
     * @param  array<string, AccountKind>  $kinds  every account to report, keyed by id
     * @return array{balances: array<string, array<string, int>>, short_from: string|null, funding_lift: array<string, int>}
     */
    private function balancesByAccount(array $kinds): array
    {
        if ($kinds === []) {
            return ['balances' => [], 'short_from' => null, 'funding_lift' => []];
        }

        // A transaction funds one deposit at most, so the join never repeats a row it sums. `transferred`
        // is the day's outbound own-transfers — a transfer split that is not a deposit's funding, the same
        // rows {@see movedOutOfReach()} counts — kept apart from `funded` so each lifts for the right reason.
        $days = DB::table('financial_transactions')
            ->leftJoin('deposit_terms', 'deposit_terms.funding_transaction_id', '=', 'financial_transactions.id')
            ->where('financial_transactions.tax_subject_id', $this->tenant->currentTenantId())
            ->whereIn('financial_transactions.account_id', array_keys($kinds))
            ->groupBy('financial_transactions.account_id', 'financial_transactions.currency_code', 'financial_transactions.booked_at')
            ->orderBy('financial_transactions.booked_at')
            ->select(['financial_transactions.account_id', 'financial_transactions.currency_code', 'financial_transactions.booked_at'])
            ->selectRaw('sum(financial_transactions.amount_minor) as total')
            ->selectRaw('sum(case when deposit_terms.funding_transaction_id is null then 0 else -financial_transactions.amount_minor end) as funded')
            ->selectRaw(
                'sum(case when financial_transactions.amount_minor < 0'
                .' and deposit_terms.funding_transaction_id is null'
                .' and exists (select 1 from transaction_splits'
                .' where transaction_splits.transaction_id = financial_transactions.id'
                .' and transaction_splits.category_code = ?)'
                .' then -financial_transactions.amount_minor else 0 end) as transferred',
                [DepositMovements::TRANSFER],
            )
            ->get();

        $balances = [];
        $firstDay = [];
        $fundingLift = [];

        foreach ($days as $day) {
            $accountId = self::str($day->account_id);
            $currency = self::str($day->currency_code);
            $balance = ($balances[$accountId][$currency] ?? 0) + (int) $day->total;
            $firstDay[$accountId] ??= self::str($day->booked_at);

            if ($kinds[$accountId]->isStream() && $balance < 0) {
                // What left that day but is still the taxpayer's: a deposit's funding and an own-transfer
                // to another bank. An account can't hold less than nothing, so where one of these drove it
                // below zero the money was there, just never imported — lift back up by that much, no more.
                $stillOwned = (int) $day->funded + (int) $day->transferred;
                $lift = min(-$balance, $stillOwned);
                $balance += $lift;
                // Only the funding share is netted against `movedOutOfReach()` later: that money is now a
                // deposit's principal and cannot also still be sitting in the other bank. Funding first, so
                // the netted share is never overstated on a day that both funds and transfers.
                $fundingLift[$currency] = ($fundingLift[$currency] ?? 0) + min($lift, (int) $day->funded);
            }

            $balances[$accountId][$currency] = $balance;
        }

        $shortFrom = null;

        foreach ($balances as $accountId => $byCurrency) {
            if ($kinds[$accountId]->isStream() && min($byCurrency) < 0) {
                $day = substr($firstDay[$accountId], 0, 10);
                $shortFrom = $shortFrom === null ? $day : min($shortFrom, $day);
            }
        }

        return [
            'balances' => array_map(static function (array $byCurrency): array {
                ksort($byCurrency);

                return $byCurrency;
            }, $balances),
            'short_from' => $shortFrom,
            'funding_lift' => $fundingLift,
        ];
    }

    /**
     * @param  array<string, int>  $byCurrency
     * @return list<array{currency_code: string, amount_minor: int}>
     */
    private static function balanceList(array $byCurrency): array
    {
        return array_map(
            static fn (string $currency, int $amount): array => ['currency_code' => $currency, 'amount_minor' => $amount],
            array_keys($byCurrency),
            array_values($byCurrency),
        );
    }

    /**
     * The order accounts are listed in: running deposits by maturity, settled deposits, cash, the
     * other accounts by name, and General last.
     *
     * Sorted here, after the names are decrypted. The query used to order by the encrypted name,
     * which is no order anyone can see.
     */
    private static function listOrder(AccountSummary $a, AccountSummary $b): int
    {
        return [array_search($a->kind, self::KIND_ORDER, true), $a->deposit->closed ?? false, $a->deposit->maturityAt ?? '', mb_strtolower($a->displayName)]
            <=> [array_search($b->kind, self::KIND_ORDER, true), $b->deposit->closed ?? false, $b->deposit->maturityAt ?? '', mb_strtolower($b->displayName)];
    }

    /**
     * The rate period covering today, per deposit.
     *
     * @param  list<string>  $depositIds
     * @return array<string, string>
     */
    private function currentRates(array $depositIds, CarbonImmutable $today): array
    {
        $rows = DB::table('deposit_rate_periods')
            ->whereIn('account_id', $depositIds)
            ->whereDate('valid_from', '<=', $today->toDateString())
            ->where(function ($query) use ($today): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today->toDateString());
            })
            ->get(['account_id', 'annual_rate_decimal']);

        $rates = [];

        foreach ($rows as $row) {
            $rates[self::str($row->account_id)] = self::str($row->annual_rate_decimal);
        }

        return $rates;
    }

    /**
     * Total gross interest over each deposit's whole life, per deposit.
     *
     * A sum over every row rather than only the elapsed ones, because the stored schedule already
     * spans the full term — `Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount` computes it at opening, when
     * every input (principal, rate periods, frequency, day count, both dates) is already fixed.
     *
     * @param  list<string>  $depositIds
     * @return array<string, int>
     */
    private function interestAtMaturity(array $depositIds): array
    {
        $rows = DB::table('deposit_interest_events')
            ->whereIn('account_id', $depositIds)
            ->groupBy('account_id')
            ->selectRaw('account_id, sum(gross_interest_minor) as total')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[self::str($row->account_id)] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * Interest already paid into each deposit, net, keyed by account id — present only for a deposit
     * with at least one period on the ledger.
     *
     * One query for the page. Whether a deposit is in it is also what decides whether the terms that
     * interest was computed from are still editable — see `UpdateFinancialAccount`.
     *
     * @param  list<string>  $depositIds
     * @return array<string, int>
     */
    private function interestReceivedFor(array $depositIds): array
    {
        $received = [];

        foreach (
            DB::table('deposit_interest_events')
                ->whereIn('account_id', $depositIds)
                ->whereNotNull('transaction_id')
                ->groupBy('account_id')
                ->get(['account_id', DB::raw('sum(net_interest_minor) as total')]) as $row
        ) {
            $received[self::str($row->account_id)] = (int) $row->total;
        }

        return $received;
    }

    /**
     * Eloquent and the query builder both hand back `mixed`; a bare `(string)` cast on that is a
     * static-analysis error and, on an array or object, a runtime one. Named once rather than
     * repeated as a suppression at each call site.
     */
    private static function str(mixed $value): string
    {
        // Only ever for scalars. A `DateTimeInterface` reaching here would silently become an empty
        // string, which is how "0 days to maturity" shipped once — dates come from their casts.
        return is_scalar($value) ? (string) $value : '';
    }

}
