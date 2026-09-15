<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\DepositInterestEvent;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;
use App\Domain\Wallet\Services\DepositMovements;
use App\Domain\Wallet\Services\Fx\DisplayCurrencyConverter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The read side of the wallet: what `/dashboard`, `/wallet/accounts` and `/wallet/transactions` show.
 *
 * Every query here relies on `ScopedToTaxSubject`'s global scope for tenancy — there is no
 * `where('tax_subject_id', …)` in this class, and there must not be one. Repeating the filter by
 * hand is how a query eventually gets written without it; the scope applies to every model read
 * whether the author remembered or not (`docs/handoff/011-identity-tenancy.md`).
 *
 * Balances and interest are read through the plain query builder against
 * `account_balance_snapshots` and `deposit_interest_events`. Those two tables carry no encrypted
 * columns and are aggregated, never listed row by row, so loading Eloquent models to sum them would
 * buy nothing.
 */
final readonly class WalletQuery
{
    /** The groups "what you have" is split into, in the order the screen shows them. */
    private const string GENERAL = 'general';

    private const string DEPOSITS = 'deposits';

    private const string CASH = 'cash';

    private const string OTHER = 'other';

    /** The categories that say money was lent or paid back — `config/tax_categories.php`. */
    private const string LOAN = 'LOAN';

    private const string LOAN_REPAYMENT = 'LOAN_REPAYMENT';

    /** Kinds in the order the accounts screen lists them. */
    private const array KIND_ORDER = ['TERM_DEPOSIT', 'CASH', 'BROKER', 'CRYPTO', 'OTHER', 'GENERAL'];

    public function __construct(
        private DisplayCurrencyConverter $converter,
        private TenantContext $tenant,
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
        $balances = $this->balancesByAccount($kinds);
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
    public function transactions(?string $accountId, int $limit): array
    {
        $query = FinancialTransaction::query()
            ->orderByDesc('booked_at')
            ->limit($limit)
            // Two kinds of row are records the application keeps, not movements a person decides
            // anything about, so neither is listed. A cash holding's single row *is* its declared
            // value. A deposit's funding and settlement rows only move money between the taxpayer's
            // own sources — they exist so a deposit is not counted twice, and are never income or
            // spending. The deposit's interest is income, and stays.
            ->whereNotIn('account_id', FinancialAccount::query()->where('kind', AccountKind::CASH->value)->select('id'))
            ->whereNot(static function ($query): void {
                $query->where('source', RecordSource::SYSTEM->value)
                    ->whereExists(static function ($splits): void {
                        $splits->from('transaction_splits')
                            ->whereColumn('transaction_splits.transaction_id', 'financial_transactions.id')
                            ->where('category_code', DepositMovements::TRANSFER);
                    });
            });

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        /** @var list<FinancialTransaction> $transactions */
        $transactions = $query->get()->all();

        return $this->summarise($transactions);
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

        // One lookup for the account names rather than a relation load per row: the display name is
        // encrypted, so each read costs a decrypt, and a transaction list repeats the same handful
        // of accounts.
        $names = [];

        $kinds = [];

        foreach ($this->accounts() as $account) {
            $names[$account->id] = $account->displayName;
            $kinds[$account->id] = $account->kind;
        }

        $splits = $this->splitsByTransaction(array_map(
            static fn (FinancialTransaction $transaction): string => self::str($transaction->getKey()),
            $transactions,
        ));

        return array_map(
            function (FinancialTransaction $transaction) use ($names, $kinds, $splits): TransactionSummary {
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
     *   never below zero — see {@see heldInAccounts()} for why.
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
     * @return array<string, mixed>
     */
    public function totals(array $running): array
    {
        $held = $this->heldInAccounts();
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

        // What can be spent today: General and cash. Deposits are locked until they mature, and broker or
        // crypto accounts are invested rather than spendable, so neither is in it. Added per currency and
        // converted once, so it rounds the way every other figure here does.
        $available = $held[self::GENERAL];

        foreach ($held[self::CASH] as $currency => $amount) {
            $available[$currency] = ($available[$currency] ?? 0) + $amount;
        }

        $display = $this->displayCurrency();

        return [
            'net_worth' => $this->converter->convert($everything, $display)->toArray(),
            'holdings' => [
                ...array_map(fn (array $amounts): array => $this->converted($amounts, $display), $held),
                'available' => $this->converted($available, $display),
            ],
            'deposit_count' => count($running),
            // A forecast for the running deposits: gross, over each whole term.
            'interest_at_maturity' => $this->converted($expected, $display),
            'interest_received' => $this->converted($this->interestReceived(), $display),
            'owed_to_me' => $this->converted($this->owedToMe(), $display),
            // What a tax return would actually see. `ron_equivalent_minor` is written by
            // `DepositInterestFxAnnotator` at the BNR rate for the accrual date, with the source
            // document version recorded beside it — so this one may legitimately be a single number.
            'recognised_ron_minor' => $this->recognisedRon(),
        ];
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
                converted: $this->converter->convert($group['principal'], $display),
                nextMaturityAt: $group['next'],
            );
        }

        usort($result, static fn (BankDeposits $a, BankDeposits $b): int => $b->converted->amountMinor <=> $a->converted->amountMinor);

        return $result;
    }

    /**
     * What is recorded in each kind of account, per currency — every group but the deposits' principal.
     *
     * ## Never below zero
     *
     * Nobody tells this application what they had before they started using it, and nobody should
     * have to. So an account whose balance is the sum of its movements — General, a broker, a crypto
     * exchange — cannot know its starting figure, and a plain sum goes negative the moment money
     * leaves that was never recorded arriving. A deposit opened from General on the first day drove
     * General to minus the deposit, and "what you have" to zero.
     *
     * Such an account is therefore read day by day and held at zero at the end of any day that would take
     * it lower: whatever left beyond what was recorded is counted as money the taxpayer already had.
     * By day rather than by row, because the order of one day's movements is not known — a statement
     * lists them in no particular order — so a day's spending and income are netted first.
     * Money recorded arriving first is still moved rather than duplicated — $100 of dividends then a
     * $150 deposit leaves General at 0 and $150 in the deposit, not $250. So the figure means "at least
     * this much", never an invented balance.
     *
     * A cash holding is its declared value, and a deposit account holds only interest paid into it and
     * not yet settled; neither has an unknown history, so both are plain sums.
     *
     * @return array{general: array<string, int>, deposits: array<string, int>, cash: array<string, int>, other: array<string, int>}
     */
    private function heldInAccounts(): array
    {
        $held = [self::GENERAL => [], self::DEPOSITS => [], self::CASH => [], self::OTHER => []];
        $kinds = [];

        foreach (FinancialAccount::query()->get(['id', 'kind']) as $account) {
            $kinds[self::str($account->getKey())] = $account->kind;
        }

        foreach ($this->balancesByAccount($kinds) as $accountId => $byCurrency) {
            $group = match ($kinds[$accountId]) {
                AccountKind::GENERAL => self::GENERAL,
                AccountKind::TERM_DEPOSIT => self::DEPOSITS,
                AccountKind::CASH => self::CASH,
                AccountKind::BROKER, AccountKind::CRYPTO, AccountKind::OTHER => self::OTHER,
            };

            foreach ($byCurrency as $currency => $amount) {
                $held[$group][$currency] = ($held[$group][$currency] ?? 0) + $amount;
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

    /**
     * The subject's chosen display currency, or RON.
     *
     * Falls back rather than throwing if the subject row cannot be read: a dashboard that refuses
     * to render because a preference is missing is worse than one that adds up in lei.
     */
    private function displayCurrency(): string
    {
        $configured = DB::table('tax_subjects')
            ->where('id', $this->tenant->currentSubjectId())
            ->value('display_currency');

        return is_string($configured) && $configured !== '' ? strtoupper($configured) : 'RON';
    }

    /**
     * Interest recognised in RON, summed across every deposit.
     *
     * Safe to total, unlike a balance: each row's `ron_equivalent_minor` was converted at the BNR
     * rate for its own accrual date and carries the source document version that rate came from.
     * Null when nothing has been annotated — never zero, which would claim income of zero rather
     * than no computed income.
     */
    private function recognisedRon(): ?int
    {
        // Through the model, so its tenancy scope applies: read at the table, this summed every
        // taxpayer's interest into the one on screen.
        $annotated = DepositInterestEvent::query()->whereNotNull('ron_equivalent_minor');

        // `sum()` answers 0 for "no rows", which is exactly the ambiguity this method exists to
        // avoid — so absence is detected by counting rows, not by the sum being zero.
        if (! $annotated->exists()) {
            return null;
        }

        return (int) $annotated->sum('ron_equivalent_minor');
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
     * An account whose balance is its movements is held at zero at the end of any day that would take
     * it lower; see {@see heldInAccounts()} for why. A cash holding and a deposit account are plain sums.
     *
     * @param  array<string, AccountKind>  $kinds  every account to report, keyed by id
     * @return array<string, array<string, int>> balances per currency, keyed by account id
     */
    private function balancesByAccount(array $kinds): array
    {
        if ($kinds === []) {
            return [];
        }

        $days = DB::table('financial_transactions')
            ->whereIn('account_id', array_keys($kinds))
            ->groupBy('account_id', 'currency_code', 'booked_at')
            ->orderBy('booked_at')
            ->get(['account_id', 'currency_code', DB::raw('sum(amount_minor) as total')]);

        $balances = [];

        foreach ($days as $day) {
            $accountId = self::str($day->account_id);
            $currency = self::str($day->currency_code);
            $balance = ($balances[$accountId][$currency] ?? 0) + (int) $day->total;

            $balances[$accountId][$currency] = $kinds[$accountId]->isStream() ? max(0, $balance) : $balance;
        }

        return array_map(static function (array $byCurrency): array {
            ksort($byCurrency);

            return $byCurrency;
        }, $balances);
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
     * spans the full term — `App\Domain\Wallet\Actions\OpenFinancialAccount` computes it at opening, when
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
