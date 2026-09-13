<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\DataTransferObjects\ConvertedTotal;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\Fx\DisplayCurrencyConverter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The read side of the wallet: what `/portofel`, `/portofel/conturi` and `/portofel/depozite` show.
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
        $query = FinancialAccount::query()->with('depositTerms')->orderBy('display_name_ciphertext');

        /** @var list<FinancialAccount> $accounts */
        $accounts = $query->get()->all();

        if ($accounts === []) {
            return [];
        }

        $ids = array_map(static fn (FinancialAccount $account): string => self::str($account->getKey()), $accounts);

        $balances = $this->balancesByCurrency($ids);
        // One query each for the whole page rather than per deposit row: the rate and the interest
        // total are both simple aggregates keyed by account.
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $rates = $this->currentRates($ids, $today);
        $interest = $this->interestAtMaturity($ids);

        return array_map(
            function (FinancialAccount $account) use ($balances, $rates, $interest, $today): AccountSummary {
                $id = self::str($account->getKey());

                return new AccountSummary(
                    id: $id,
                    // Decrypted by the `Encrypted` cast on read; it never leaves this process in
                    // any other form.
                    displayName: self::str($account->display_name_ciphertext),
                    kind: $account->kind->value,
                    // A default for the form only — a source may legitimately have none.
                    currencyCode: $account->currency_code,
                    balances: $balances[$id] ?? [],
                    source: $account->source->value,
                    deposit: $this->depositFacts($account, $rates[$id] ?? null, $interest[$id] ?? null, $today),
                );
            },
            $accounts,
        );
    }

    /**
     * The deposit half of an account row, or null when the account is not one.
     */
    private function depositFacts(
        FinancialAccount $account,
        ?string $annualRate,
        ?int $interestAtMaturity,
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
            autoRenewal: $terms->auto_renewal,
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
    public function deposits(bool $includeMatured = true): array
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

        if (! $includeMatured) {
            $query->whereDate('deposit_terms.maturity_at', '>=', $today->toDateString());
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
                    autoRenewal: $terms->auto_renewal,
                    interestAtMaturityMinor: $interest[$id] ?? null,
                    // The term as bought, in days — fixed at opening, and the span the interest
                    // above was actually calculated over.
                    totalDays: (int) $opened->diffInDays($maturity, absolute: false),
                    // Negative for a matured deposit, which the screen renders as "matured" rather
                    // than as a countdown.
                    daysToMaturity: (int) $today->diffInDays($maturity, absolute: false),
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
        $query = FinancialTransaction::query()->orderByDesc('booked_at')->limit($limit);

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        /** @var list<FinancialTransaction> $transactions */
        $transactions = $query->get()->all();

        if ($transactions === []) {
            return [];
        }

        // One lookup for the account names rather than a relation load per row: the display name is
        // encrypted, so each read costs a decrypt, and a transaction list repeats the same handful
        // of accounts.
        $names = [];

        foreach ($this->accounts() as $account) {
            $names[$account->id] = $account->displayName;
        }

        $splits = $this->splitsByTransaction(array_map(
            static fn (FinancialTransaction $transaction): string => self::str($transaction->getKey()),
            $transactions,
        ));

        return array_map(
            function (FinancialTransaction $transaction) use ($names, $splits): TransactionSummary {
                $accountId = self::str($transaction->account_id);

                return new TransactionSummary(
                    id: self::str($transaction->getKey()),
                    accountId: $accountId,
                    accountName: $names[$accountId] ?? '',
                    bookedAt: $transaction->booked_at->toDateString(),
                    valueAt: $transaction->value_at?->toDateString(),
                    amountMinor: (int) $transaction->amount_minor,
                    currencyCode: self::str($transaction->currency_code),
                    description: self::str($transaction->description_ciphertext),
                    counterparty: ($counterparty = self::str($transaction->counterparty_ciphertext)) === ''
                        ? null
                        : $counterparty,
                    classificationStatus: $transaction->classification_status->value,
                    splits: $splits[self::str($transaction->getKey())] ?? [],
                    source: $transaction->source->value,
                );
            },
            $transactions,
        );
    }

    /**
     * Totals for the overview.
     *
     * Deliberately **per currency**, not one summed number. Adding 72,480 RON to 1,200 EUR is
     * meaningless without a conversion, and the conversion is a fiscal decision with a rate, a date
     * and a source — `App\Domain\TaxEngine\Services\FxConversionPolicy`'s job, not a dashboard's.
     * A single "total balance" here would be the screen inventing an exchange rate.
     *
     * @return array{balances: array<string, int>, deposits: array<string, int>, accounts: int, deposit_count: int, interest_at_maturity: array<string, int>, net_worth: array<string, mixed>}
     */
    public function totals(): array
    {
        $balances = [];

        // Each source now reports one figure per currency, so the page total is a sum of those
        // rather than of one number per source — which is what made the previous version add
        // euros to dollars before anyone could notice.
        foreach ($this->accounts() as $account) {
            foreach ($account->balances as $balance) {
                $currency = $balance['currency_code'];
                $balances[$currency] = ($balances[$currency] ?? 0) + $balance['amount_minor'];
            }
        }

        $deposits = [];
        // What the running deposits will have earned by the time they mature — a forecast, and
        // labelled as one on the screen. Matured deposits are excluded (`includeMatured: false`)
        // because their interest is already earned rather than still coming.
        $expected = [];
        $depositCount = 0;

        foreach ($this->deposits(includeMatured: false) as $deposit) {
            $deposits[$deposit->currencyCode] = ($deposits[$deposit->currencyCode] ?? 0) + $deposit->principalMinor;
            $depositCount++;

            if ($deposit->interestAtMaturityMinor !== null) {
                $expected[$deposit->currencyCode] = ($expected[$deposit->currencyCode] ?? 0) + $deposit->interestAtMaturityMinor;
            }
        }

        return [
            'balances' => $balances,
            'deposits' => $deposits,
            'interest_at_maturity' => $expected,
            // Balances and deposit principals added into the subject's chosen display currency, at
            // the latest published BNR rate. A dashboard convenience, computed on demand and never
            // stored — it comes back with the rates and dates it used, and the screen labels it as
            // an indication. A fiscal conversion is a `TaxRule`'s own decision (`BLUEPRINT.md`
            // §7.4) and is never this.
            'net_worth' => $this->netWorth($balances, $deposits)->toArray(),
            'accounts' => count($this->accounts()),
            'deposit_count' => $depositCount,
            // What a tax return would actually see. `ron_equivalent_minor` is written by
            // `DepositInterestFxAnnotator` at the BNR rate for the accrual date, with the source
            // document version recorded beside it — so unlike the per-currency sums above, this one
            // may legitimately be a single number: the conversion already happened, with provenance.
            'recognised_ron_minor' => $this->recognisedRon(),
        ];
    }

    /**
     * Everything a subject holds, in one currency.
     *
     * Balances and deposit principals are summed per currency first and converted once, rather than
     * converting each account: fewer rate lookups, and the rounding happens in one place instead of
     * accumulating across rows.
     *
     * @param  array<string, int>  $balances
     * @param  array<string, int>  $deposits
     */
    private function netWorth(array $balances, array $deposits): ConvertedTotal
    {
        $byCurrency = $balances;

        foreach ($deposits as $currency => $amount) {
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $amount;
        }

        return $this->converter->convert($byCurrency, $this->displayCurrency());
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
        // `sum()` answers 0 for "no rows", which is exactly the ambiguity this method exists to
        // avoid — so absence is detected by counting rows, not by the sum being zero.
        if (! DB::table('deposit_interest_events')->whereNotNull('ron_equivalent_minor')->exists()) {
            return null;
        }

        return (int) DB::table('deposit_interest_events')->sum('ron_equivalent_minor');
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
     * What has actually been recorded against each source, totalled per currency.
     *
     * Grouped in SQL rather than summed in PHP, and grouped by *currency* rather than by account
     * alone — the previous version added every `amount_minor` together whatever its denomination and
     * labelled the result with the account's currency, so a source holding euros and dollars
     * reported their numeric sum as euros.
     *
     * A currency whose movements cancel to zero is kept: "this source had dollars in it and they are
     * gone" is a different statement from "this source never had dollars", and only one of them
     * should disappear from a screen.
     *
     * @param  list<string>  $accountIds
     * @return array<string, list<array{currency_code: string, amount_minor: int}>>
     */
    private function balancesByCurrency(array $accountIds): array
    {
        $rows = DB::table('financial_transactions')
            ->whereIn('account_id', $accountIds)
            ->groupBy('account_id', 'currency_code')
            ->orderBy('currency_code')
            ->get(['account_id', 'currency_code', DB::raw('sum(amount_minor) as total')]);

        $byAccount = [];

        foreach ($rows as $row) {
            $byAccount[self::str($row->account_id)][] = [
                'currency_code' => self::str($row->currency_code),
                'amount_minor' => (int) $row->total,
            ];
        }

        return $byAccount;
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
