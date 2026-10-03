<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Models\Tag;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;
use Kern\TaxRomania\Wallet\Services\CounterpartyTransactions;

/**
 * The parties this taxpayer has dealt with, busiest first.
 *
 * ## One row per name, not per rule
 *
 * `counterparty_rules` holds a row per direction, so a party you have both paid and been paid by is
 * two rows there. A person does not think of that as two parties — they think "Bruma Mihaela, I lend
 * her money and she pays it back" — and the two decisions that matter most on this screen are
 * exactly cross-direction: filling the side that has no answer, and merging spellings. So the rows
 * are folded by their blind index, and each direction's rule is a field.
 *
 * ## Why the counting is done in SQL and the naming is not
 *
 * A name is encrypted, so ordering or filtering by it means decrypting every party — 2585 of them
 * here, which is what makes {@see CounterpartyDirectory::search()} cost what it costs. The row count
 * per party is a plain `group by` on an indexed column, so the ordering, the window and the totals
 * are all decided before anything is decrypted; only the page's own names are opened. Twenty-five
 * decryptions instead of 2585.
 *
 * Searching is the exception and cannot be anything else: matching part of a name requires the
 * names. That path pays the full cost, which is the price of searching encrypted text and the
 * reason the default ordering does not need it.
 */
final readonly class CounterpartyList
{
    public function __construct(
        private TenantContext $tenant,
        private CounterpartyDirectory $directory,
        private CounterpartyTransactions $rows,
    ) {
    }

    /**
     * @return array{
     *     counterparties: list<array<string, mixed>>,
     *     total: int
     * }
     */
    public function page(?string $search, int $page, int $perPage): array
    {
        $counts = $this->rowCounts();
        $indexes = $this->orderedIndexes($search, $counts);
        $total = count($indexes);

        $window = array_slice($indexes, ($page - 1) * $perPage, $perPage);

        return [
            'counterparties' => $window === [] ? [] : $this->describe($window, $counts),
            'total' => $total,
        ];
    }

    /**
     * How many transactions each party has, by blind index.
     *
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $rows = DB::table('financial_transactions')
            ->where('tax_subject_id', $this->tenant->currentTenantId())
            ->whereNotNull('counterparty_blind_index')
            ->groupBy('counterparty_blind_index')
            ->get(['counterparty_blind_index', DB::raw('count(*) as rows_count')]);

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->counterparty_blind_index] = (int) $row->rows_count;
        }

        return $counts;
    }

    /**
     * Every party's blind index, busiest first — or only those matching a search.
     *
     * A party with rules and no transactions still belongs in the list: it is usually one whose rows
     * were merged away or deleted, and leaving it out would make it unreachable.
     *
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function orderedIndexes(?string $search, array $counts): array
    {
        if ($search !== null && mb_strlen(trim($search)) >= 2) {
            $matched = [];

            foreach ($this->directory->search($search, PHP_INT_MAX) as $match) {
                foreach ($match['indexes'] as $index) {
                    $matched[$index] = true;
                }
            }

            $indexes = array_keys($matched);
        } else {
            /** @var list<string> $indexes */
            $indexes = Counterparty::query()
                ->distinct()
                ->pluck('counterparty_blind_index')
                ->all();
        }

        usort(
            $indexes,
            static fn (string $a, string $b): int => ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0) ?: strcmp($a, $b),
        );

        return $indexes;
    }

    /**
     * The page's parties, named and with both directions' rules.
     *
     * @param  list<string>  $indexes
     * @param  array<string, int>  $counts
     * @return list<array<string, mixed>>
     */
    private function describe(array $indexes, array $counts): array
    {
        $parties = [];

        $rules = Counterparty::query()
            ->whereIn('counterparty_blind_index', $indexes)
            ->with('tags:id')
            ->get();

        foreach ($rules as $rule) {
            $index = $rule->counterparty_blind_index;

            $parties[$index] ??= [
                'name' => null,
                'rows' => $counts[$index] ?? 0,
                'in' => null,
                'out' => null,
            ];

            // Either direction's row carries the same name, so the first one seen names the party.
            $parties[$index]['name'] ??= $rule->counterparty_ciphertext;

            $parties[$index][$rule->direction === MoneyDirection::OUT ? 'out' : 'in'] = [
                'id' => $rule->id,
                // So the screen can say what changing this rule would overwrite, before it does.
                'answered' => $this->rows->answeredCount($rule->tax_subject_id, $rule),
                'category_code' => $rule->category_code,
                'country_code' => $rule->country_code,
                'source' => $rule->source?->value,
                'tag_ids' => array_values(array_map(
                    static fn (Tag $tag): string => $tag->id,
                    $rule->tags->all(),
                )),
            ];
        }

        // Back into the order the window was chosen in, which `whereIn` does not preserve.
        return array_values(array_filter(array_map(
            static fn (string $index): ?array => $parties[$index] ?? null,
            $indexes,
        )));
    }
}
