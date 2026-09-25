<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Services\DepositMovements;
use App\Domain\Wallet\Services\TagDirectory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private TenantContext $tenant,
        private TagDirectory $tags,
    ) {
    }

    /**
     * @return array{
     *     by_tag: list<array{tag_id: string, name: string, currency_code: string, total_minor: int, months: list<array{month: string, amount_minor: int}>}>,
     *     by_bank: list<array{bank_id: ?string, name: ?string, currency_code: string, in_minor: int, out_minor: int}>,
     *     by_category: list<array{category_code: string, currency_code: string, total_minor: int}>
     * }
     */
    public function between(string $from, string $to): array
    {
        return [
            'by_tag' => $this->byTag($from, $to),
            'by_bank' => $this->byBank($from, $to),
            'by_category' => $this->byCategory($from, $to),
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
            ->whereNot(static function ($query): void {
                $query->where('financial_transactions.source', RecordSource::SYSTEM->value)
                    ->whereExists(static function ($splits): void {
                        $splits->from('transaction_splits')
                            ->whereColumn('transaction_splits.transaction_id', 'financial_transactions.id')
                            ->where('category_code', DepositMovements::TRANSFER);
                    });
            });
    }
}
