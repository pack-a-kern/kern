<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Services\DepositMovements;
use Kern\TaxRomania\Wallet\Services\TagDirectory;

/**
 * What a year of transactions adds up to: by tag, by bank, by category.
 *
 * ## Why the totals are per currency
 *
 * Because 100 EUR and 100 RON are not 200 of anything. Every figure here is grouped by its own
 * currency and reported that way — the dashboard converts for display with a rate and a date beside
 * it, and a report that quietly added them would be inventing a number nobody can check.
 *
 * ## Why these three questions
 *
 * They are the ones tags and banks were added for: "what did I spend on food", "how much came
 * through each bank", "what did each category come to". None of them is a tax figure — a return is
 * computed by the tax engine from versioned rules, and these are a person reading their own year.
 *
 * ## What is left out
 *
 * A deposit's own funding transfer and a cash holding's declared value: neither is a payment anybody
 * made, and both would appear as enormous outliers in a report about spending.
 */
final readonly class WalletReports
{
    /**
     * Per direction, so the panel shows ten rows: the five biggest payments and the five biggest
     * receipts. Few enough that decrypting each one's counterparty is not a cost worth naming, and
     * enough that a year's totals have something behind them.
     */
    private const int LARGEST = 5;

    /** How many lines a ranked breakdown shows before the tail stops being worth a row. */
    private const int BREAKDOWN = 10;

    public function __construct(
        private TenantContext $tenant,
        private TagDirectory $tags,
        private DuplicateTransactionGroups $duplicates,
    ) {
    }

    /**
     * @return array{
     *     by_tag: list<array{tag_id: string, name: string, currency_code: string, total_minor: int, months: list<array{month: string, amount_minor: int}>}>,
     *     by_bank: list<array{bank_id: ?string, name: ?string, currency_code: string, in_minor: int, out_minor: int}>,
     *     by_category: list<array{category_code: string, currency_code: string, total_minor: int}>,
     *     by_counterparty: list<array{name: ?string, currency_code: string, total_minor: int, count: int}>,
     *     by_account: list<array{account_id: string, name: ?string, kind: string, currency_code: string, in_minor: int, out_minor: int}>,
     *     years: list<int>,
     *     totals: list<array{currency_code: string, in_minor: int, out_minor: int, count: int}>,
     *     previous: list<array{currency_code: string, in_minor: int, out_minor: int, count: int}>,
     *     cash_flow: list<array{month: string, currency_code: string, in_minor: int, out_minor: int, count: int, top_category: ?string}>,
     *     largest: list<array{id: string, booked_at: string, counterparty: ?string, description: ?string, currency_code: string, amount_minor: int}>,
     *     attention: array{unclassified_count: int, unclassified_minor: list<array{currency_code: string, total_minor: int}>, duplicate_groups: int, total_count: int}
     * }
     */
    public function between(string $from, string $to): array
    {
        return [
            'years' => $this->years(),
            'totals' => $this->totals($from, $to),
            'previous' => $this->totals(...$this->precedingPeriod($from, $to)),
            'by_tag' => $this->byTag($from, $to),
            'by_bank' => $this->byBank($from, $to),
            'by_category' => $this->byCategory($from, $to),
            'by_counterparty' => $this->byCounterparty($from, $to),
            'by_account' => $this->byAccount($from, $to),
            'cash_flow' => $this->cashFlow($from, $to),
            'largest' => $this->largest($from, $to),
            'attention' => $this->attention($from, $to),
        ];
    }

    /**
     * The years this taxpayer actually has transactions in, newest first.
     *
     * Offered instead of the last four calendar years, which is a menu of mostly empty reports for
     * somebody who started in March and a menu missing their history for somebody who imported ten
     * years of statements. Deliberately not bounded by the period being viewed — it is the list of
     * periods available, so bounding it by one of them would leave a menu that cannot be left.
     *
     * @return list<int>
     */
    private function years(): array
    {
        $rows = DB::table('financial_transactions')
            ->where('tax_subject_id', $this->tenant->currentTenantId())
            ->groupBy(DB::raw('extract(year from booked_at)'))
            ->orderBy(DB::raw('extract(year from booked_at)'), 'desc')
            ->get([DB::raw('extract(year from booked_at) as year')]);

        return array_values(array_map(static fn (object $row): int => (int) $row->year, $rows->all()));
    }

    /**
     * The same length of time, immediately before — what "versus last year" compares against.
     *
     * Measured rather than assumed to be a calendar year: the period may be a rolling twelve months
     * or any range the caller passed, and comparing a half-year against a full one would report a
     * halving that never happened.
     *
     * @return array{0: string, 1: string}
     */
    private function precedingPeriod(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $days = $start->diffInDays($end) + 1;

        return [$start->subDays($days)->toDateString(), $start->subDay()->toDateString()];
    }

    /**
     * What the period came to, per currency: in, out, and how many movements made it.
     *
     * The figures the screen leads with, and the ones every panel below is a breakdown of. Counted
     * here rather than summed from `cash_flow` on the client, so a month with no rows cannot quietly
     * drop out of a total.
     *
     * @return list<array{currency_code: string, in_minor: int, out_minor: int, count: int}>
     */
    private function totals(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->groupBy('financial_transactions.currency_code')
            ->get([
                'financial_transactions.currency_code',
                DB::raw('sum(case when amount_minor > 0 then amount_minor else 0 end) as money_in'),
                DB::raw('sum(case when amount_minor < 0 then -amount_minor else 0 end) as money_out'),
                DB::raw('count(*) as rows_count'),
            ]);

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'currency_code' => (string) $row->currency_code,
                'in_minor' => (int) $row->money_in,
                'out_minor' => (int) $row->money_out,
                'count' => (int) $row->rows_count,
            ];
        }

        return $result;
    }

    /**
     * Money in and money out, month by month.
     *
     * The same shape `byTag` already produces per tag, but for everything at once — which is the
     * question a person actually opens a report with, and the one the three breakdowns could only
     * answer by being added up by eye.
     *
     * Per currency, like every other figure here: a month holding 100 EUR in and 100 RON out has no
     * single net number, and inventing one is the thing this class refuses to do.
     *
     * @return list<array{month: string, currency_code: string, in_minor: int, out_minor: int, count: int, top_category: ?string}>
     */
    private function cashFlow(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->groupBy(DB::raw("to_char(booked_at, 'YYYY-MM')"), 'financial_transactions.currency_code')
            ->orderBy(DB::raw("to_char(booked_at, 'YYYY-MM')"))
            ->get([
                DB::raw("to_char(booked_at, 'YYYY-MM') as month"),
                'financial_transactions.currency_code',
                DB::raw('sum(case when amount_minor > 0 then amount_minor else 0 end) as money_in'),
                DB::raw('sum(case when amount_minor < 0 then -amount_minor else 0 end) as money_out'),
                DB::raw('count(*) as rows_count'),
            ]);

        $top = $this->topCategoryByMonth($from, $to);
        $result = [];

        foreach ($rows as $row) {
            $month = (string) $row->month;

            $result[] = [
                'month' => $month,
                'currency_code' => (string) $row->currency_code,
                'in_minor' => (int) $row->money_in,
                'out_minor' => (int) $row->money_out,
                'count' => (int) $row->rows_count,
                'top_category' => $top[$month] ?? null,
            ];
        }

        return $result;
    }

    /**
     * The category each month spent most on.
     *
     * One query for the whole year rather than one per month: twelve round trips to label twelve
     * rows is the shape that makes a table feel slow for no reason a reader could name.
     *
     * @return array<string, string>
     */
    private function topCategoryByMonth(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->join('transaction_splits', 'transaction_splits.transaction_id', '=', 'financial_transactions.id')
            ->where('transaction_splits.amount_minor', '<', 0)
            ->groupBy(DB::raw("to_char(booked_at, 'YYYY-MM')"), 'transaction_splits.category_code')
            ->orderByRaw("to_char(booked_at, 'YYYY-MM'), sum(-transaction_splits.amount_minor) desc")
            ->get([
                DB::raw("to_char(booked_at, 'YYYY-MM') as month"),
                'transaction_splits.category_code',
            ]);

        $top = [];

        foreach ($rows as $row) {
            // Ordered biggest-first within each month, so the first one seen is the answer.
            $top[(string) $row->month] ??= (string) $row->category_code;
        }

        return $top;
    }

    /**
     * What went out to each counterparty, biggest first.
     *
     * The one breakdown that has to decrypt, so it is built to decrypt as little as possible: group
     * by the blind index, which needs no key, take the few that lead, and open one name per group.
     * Ten decrypts rather than one per transaction — the whole ledger would be thousands.
     *
     * @return list<array{name: ?string, currency_code: string, total_minor: int, count: int}>
     */
    private function byCounterparty(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->where('amount_minor', '<', 0)
            ->whereNotNull('counterparty_blind_index')
            ->groupBy('counterparty_blind_index', 'financial_transactions.currency_code')
            ->orderByRaw('sum(-amount_minor) desc')
            ->limit(self::BREAKDOWN)
            ->get([
                'counterparty_blind_index',
                'financial_transactions.currency_code',
                DB::raw('sum(-amount_minor) as total'),
                DB::raw('count(*) as rows_count'),
                DB::raw('min(financial_transactions.id::text) as sample_id'),
            ]);

        $sampleIds = array_map(static fn (object $row): string => (string) $row->sample_id, $rows->all());
        $names = [];

        if ($sampleIds !== []) {
            foreach (FinancialTransaction::query()->whereIn('id', $sampleIds)->get() as $transaction) {
                $names[$transaction->id] = $transaction->counterparty_ciphertext;
            }
        }

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'name' => $names[(string) $row->sample_id] ?? null,
                'currency_code' => (string) $row->currency_code,
                'total_minor' => (int) $row->total,
                'count' => (int) $row->rows_count,
            ];
        }

        return $result;
    }

    /**
     * What moved through each source — the same question as `byBank`, asked of accounts.
     *
     * A person with two cards at one bank sees one line there and two here, which is the difference
     * the tab exists for. Named from the account's own display name, which is encrypted, so the
     * names are opened a handful at a time like the counterparties above.
     *
     * @return list<array{account_id: string, name: ?string, kind: string, currency_code: string, in_minor: int, out_minor: int}>
     */
    private function byAccount(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->groupBy('financial_transactions.account_id', 'financial_transactions.currency_code')
            ->get([
                'financial_transactions.account_id',
                'financial_transactions.currency_code',
                DB::raw('sum(case when amount_minor > 0 then amount_minor else 0 end) as money_in'),
                DB::raw('sum(case when amount_minor < 0 then -amount_minor else 0 end) as money_out'),
            ]);

        $accountIds = array_map(static fn (object $row): string => (string) $row->account_id, $rows->all());
        $accounts = [];

        if ($accountIds !== []) {
            foreach (FinancialAccount::query()->whereIn('id', $accountIds)->get() as $account) {
                $accounts[$account->id] = $account;
            }
        }

        $result = [];

        foreach ($rows as $row) {
            $account = $accounts[(string) $row->account_id] ?? null;

            $result[] = [
                'account_id' => (string) $row->account_id,
                'name' => $account?->display_name_ciphertext,
                'kind' => $account === null ? '' : $account->kind->value,
                'currency_code' => (string) $row->currency_code,
                'in_minor' => (int) $row->money_in,
                'out_minor' => (int) $row->money_out,
            ];
        }

        usort($result, static fn (array $a, array $b): int => ($b['in_minor'] + $b['out_minor']) <=> ($a['in_minor'] + $a['out_minor']));

        return $result;
    }

    /**
     * The handful of movements big enough to explain a total.
     *
     * Hydrated as models rather than read as rows, because the counterparty and the description are
     * encrypted and the cast is what knows how to open them. That costs a model per row, which is
     * why this is capped — the cap is the point of the panel, not a limitation of it.
     *
     * @return list<array{id: string, booked_at: string, counterparty: ?string, description: ?string, currency_code: string, amount_minor: int}>
     */
    private function largest(string $from, string $to): array
    {
        $out = $this->base($from, $to)
            ->where('amount_minor', '<', 0)
            ->orderBy('amount_minor')
            ->limit(self::LARGEST)
            ->get(['financial_transactions.id']);
        $in = $this->base($from, $to)
            ->where('amount_minor', '>', 0)
            ->orderByDesc('amount_minor')
            ->limit(self::LARGEST)
            ->get(['financial_transactions.id']);

        $ids = array_map(
            static fn (object $row): string => (string) $row->id,
            [...$out->all(), ...$in->all()],
        );

        if ($ids === []) {
            return [];
        }

        $result = [];

        foreach (FinancialTransaction::query()->whereIn('id', $ids)->get() as $transaction) {
            $result[] = [
                'id' => $transaction->id,
                'booked_at' => $transaction->booked_at->toDateString(),
                'counterparty' => $transaction->counterparty_ciphertext,
                'description' => $transaction->description_ciphertext,
                'currency_code' => $transaction->currency_code,
                'amount_minor' => $transaction->amount_minor,
            ];
        }

        usort($result, static fn (array $a, array $b): int => abs($b['amount_minor']) <=> abs($a['amount_minor']));

        return $result;
    }

    /**
     * What would make this report wrong if left alone.
     *
     * Two things, both already defined elsewhere and deliberately not redefined here: money in that
     * nobody has said what it is — the worklist `WalletQuery` offers, which is income-only because
     * what goes out is spending unless someone says otherwise — and the lookalike groups the
     * duplicates view suggests, on the same day/amount/currency rule, so the two screens cannot
     * disagree about how many there are.
     *
     * @return array{unclassified_count: int, unclassified_minor: list<array{currency_code: string, total_minor: int}>, duplicate_groups: int, total_count: int}
     */
    private function attention(string $from, string $to): array
    {
        $unclassified = $this->base($from, $to)
            ->where('classification_status', TransactionClassificationStatus::UNCLASSIFIED->value)
            ->where('amount_minor', '>', 0)
            ->groupBy('financial_transactions.currency_code')
            ->get([
                'financial_transactions.currency_code',
                DB::raw('count(*) as rows_count'),
                DB::raw('sum(amount_minor) as total'),
            ]);

        $count = 0;
        $totals = [];

        foreach ($unclassified as $row) {
            $count += (int) $row->rows_count;
            $totals[] = [
                'currency_code' => (string) $row->currency_code,
                'total_minor' => (int) $row->total,
            ];
        }

        // Asked of the rule rather than restated here. This used to run the day/amount/currency
        // grouping itself and report the result, which is the candidate bucket and not a group: a
        // group also has to name one counterparty and draw from more than one import. On real data
        // that was 26 against the duplicates screen's 0.
        $groups = $this->duplicates->count();

        return [
            'unclassified_count' => $count,
            'unclassified_minor' => $totals,
            'duplicate_groups' => $groups,
            // What the two counts above are a share of. Sent rather than derived on the client from
            // `totals`, which is per currency — a completeness figure is about rows, not money.
            'total_count' => $this->base($from, $to)->count(),
        ];
    }

    /**
     * Spending per tag, month by month.
     *
     * Money out only: a tag on an incoming payment is a label on income, and mixing the two into one
     * "total" would be a number that means nothing. Tag names are decrypted once for the taxpayer
     * rather than joined, exactly as the transaction list does it.
     *
     * @return list<array{tag_id: string, name: string, currency_code: string, total_minor: int, months: list<array{month: string, amount_minor: int}>}>
     */
    private function byTag(string $from, string $to): array
    {
        $names = $this->tags->all();
        $rows = $this->base($from, $to)
            ->join('transaction_tags', 'transaction_tags.transaction_id', '=', 'financial_transactions.id')
            ->where('financial_transactions.amount_minor', '<', 0)
            ->groupBy('transaction_tags.tag_id', 'financial_transactions.currency_code', DB::raw("to_char(booked_at, 'YYYY-MM')"))
            ->get([
                'transaction_tags.tag_id',
                'financial_transactions.currency_code',
                DB::raw("to_char(booked_at, 'YYYY-MM') as month"),
                DB::raw('sum(financial_transactions.amount_minor) as total'),
            ]);

        $grouped = [];

        foreach ($rows as $row) {
            $tagId = (string) $row->tag_id;
            $currency = (string) $row->currency_code;
            $key = $tagId . '|' . $currency;

            $grouped[$key] ??= [
                'tag_id' => $tagId,
                'name' => $names[$tagId] ?? '',
                'currency_code' => $currency,
                'total_minor' => 0,
                'months' => [],
            ];

            // Reported as a positive amount spent: a report of outgoings does not need every figure
            // to carry a minus that says what the heading already says.
            $amount = abs((int) $row->total);
            $grouped[$key]['total_minor'] += $amount;
            $grouped[$key]['months'][] = ['month' => (string) $row->month, 'amount_minor' => $amount];
        }

        $result = array_values($grouped);

        usort($result, static fn (array $a, array $b): int => $b['total_minor'] <=> $a['total_minor']);

        foreach ($result as $index => $entry) {
            usort($entry['months'], static fn (array $a, array $b): int => strcmp($a['month'], $b['month']));
            $result[$index] = $entry;
        }

        return $result;
    }

    /**
     * What came in and what went out, per bank.
     *
     * Rows with no bank are gathered under a null id rather than dropped: money typed in by hand
     * before the bank was known is still money, and hiding it would make the totals disagree with
     * the transactions list.
     *
     * @return list<array{bank_id: ?string, name: ?string, currency_code: string, in_minor: int, out_minor: int}>
     */
    private function byBank(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->groupBy('financial_transactions.bank_id', 'financial_transactions.currency_code')
            ->get([
                'financial_transactions.bank_id',
                'financial_transactions.currency_code',
                DB::raw('sum(case when amount_minor > 0 then amount_minor else 0 end) as money_in'),
                DB::raw('sum(case when amount_minor < 0 then -amount_minor else 0 end) as money_out'),
            ]);

        $bankIds = array_values(array_filter(array_map(
            static fn (object $row): ?string => $row->bank_id === null ? null : (string) $row->bank_id,
            $rows->all(),
        )));

        $names = [];

        if ($bankIds !== []) {
            foreach (DB::table('ext_banks')->whereIn('id', $bankIds)->get(['id', 'name']) as $bank) {
                $names[(string) $bank->id] = (string) $bank->name;
            }
        }

        $result = [];

        foreach ($rows as $row) {
            $bankId = $row->bank_id === null ? null : (string) $row->bank_id;

            $result[] = [
                'bank_id' => $bankId,
                'name' => $bankId === null ? null : ($names[$bankId] ?? null),
                'currency_code' => (string) $row->currency_code,
                'in_minor' => (int) $row->money_in,
                'out_minor' => (int) $row->money_out,
            ];
        }

        usort($result, static fn (array $a, array $b): int => ($b['in_minor'] + $b['out_minor']) <=> ($a['in_minor'] + $a['out_minor']));

        return $result;
    }

    /**
     * What each category came to.
     *
     * From the splits, because that is where a category lives — and a split carries its own amount,
     * so a transaction divided between two categories counts correctly in both.
     *
     * @return list<array{category_code: string, currency_code: string, total_minor: int}>
     */
    private function byCategory(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->join('transaction_splits', 'transaction_splits.transaction_id', '=', 'financial_transactions.id')
            ->groupBy('transaction_splits.category_code', 'financial_transactions.currency_code')
            ->get([
                'transaction_splits.category_code',
                'financial_transactions.currency_code',
                DB::raw('sum(transaction_splits.amount_minor) as total'),
            ]);

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'category_code' => (string) $row->category_code,
                'currency_code' => (string) $row->currency_code,
                'total_minor' => (int) $row->total,
            ];
        }

        usort($result, static fn (array $a, array $b): int => abs($b['total_minor']) <=> abs($a['total_minor']));

        return $result;
    }

    /**
     * The transactions every report reads: this taxpayer's, in the period, excluding the rows that
     * are not payments anybody made.
     *
     * Scoped by an explicit `where` rather than a model: these are aggregates over hundreds of rows,
     * and loading Eloquent models to sum them would buy nothing — the same reasoning
     * {@see WalletQuery} states for the balance queries. The subject comes from the tenant context,
     * never from a request parameter.
     */
    private function base(string $from, string $to): \Illuminate\Database\Query\Builder
    {
        return DB::table('financial_transactions')
            ->where('financial_transactions.tax_subject_id', $this->tenant->currentTenantId())
            ->whereDate('booked_at', '>=', $from)
            ->whereDate('booked_at', '<=', $to)
            ->whereNotIn(
                'account_id',
                DB::table('financial_accounts')
                    ->where('tax_subject_id', $this->tenant->currentTenantId())
                    ->where('kind', AccountKind::CASH->value)
                    ->select('id'),
            )
            /*
             * A transfer is money moving between the taxpayer's own places, so it is neither income
             * nor spending whoever recorded it.
             *
             * This used to exclude only the rows this application writes itself, which meant a
             * statement's own deposit movements were counted. One imported year read as $200,719 in
             * and $200,719 out, because a $100,000 deposit matured and was re-constituted twice and
             * each leg counted on both sides — a year that earned $87.92 in interest, reported as
             * half a million dollars of activity. Marking those rows as transfers did nothing,
             * because the exclusion also demanded they be ours.
             */
            ->whereNot(static function ($query): void {
                $query->whereExists(static function ($splits): void {
                    $splits->from('transaction_splits')
                        ->whereColumn('transaction_splits.transaction_id', 'financial_transactions.id')
                        ->where('category_code', DepositMovements::TRANSFER);
                });
            });
    }
}
