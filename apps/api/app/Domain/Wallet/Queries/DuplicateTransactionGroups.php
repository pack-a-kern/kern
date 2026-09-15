<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\DuplicateSurvivor;
use App\Domain\Wallet\Services\LookalikeTransactions;
use App\Support\Text\PartyName;

/**
 * Transactions that look like the same payment recorded more than once.
 *
 * "Looks like" is: the same day, the same amount, the same currency, and a counterparty that could
 * be the same party. That is nearly always a duplicate — a receipt imported and then the statement
 * row for the same payment — and occasionally two genuine payments, which is why this only gathers
 * them for a person to decide rather than acting on them.
 *
 * ## Why the counterparty is compared here and not in SQL
 *
 * It is encrypted. The figures narrow the table to a handful of candidate rows in one grouped
 * query; only those are decrypted, and compared with {@see PartyName::sharesName()}, because
 * "KAUFLAND 1234 BUCURESTI" on a statement and "Kaufland Romania SCS" on a receipt are one shop and
 * equality would never say so. A row with no counterparty at all matches any: a hand-typed
 * transaction often leaves it empty, and "nobody named" is not evidence of a different party.
 *
 * ## Why a group can outlive being kept
 *
 * A group is shown while at least one of its rows has not been reviewed. Keeping marks every row,
 * so the group disappears — until a new identical payment arrives, whose row is unmarked.
 */
final readonly class DuplicateTransactionGroups
{
    /**
     * Enough for any real backlog, and a bound on how many rows one request decrypts. A person with
     * more candidate days than this is shown the most recent first and sees the rest as they clear.
     */
    private const int MAX_CANDIDATE_DAYS = 200;

    public function __construct(private WalletQuery $wallet)
    {
    }

    /**
     * @return list<array{survivor_id: string, transactions: list<TransactionSummary>}>
     */
    public function __invoke(): array
    {
        $groups = [];

        foreach ($this->candidateBuckets() as $rows) {
            foreach ($this->clusters($rows) as $cluster) {
                if (count($cluster) < 2 || ! $this->hasUnreviewed($cluster)) {
                    continue;
                }

                /** @var non-empty-list<FinancialTransaction> $cluster */
                $groups[] = [
                    'survivor_id' => LookalikeTransactions::id(DuplicateSurvivor::pick($cluster)),
                    'transactions' => $this->wallet->summarise($cluster),
                ];
            }
        }

        return $groups;
    }

    /**
     * Rows sharing a day, an amount and a currency with at least one other row, grouped by those.
     *
     * @return list<list<FinancialTransaction>>
     */
    private function candidateBuckets(): array
    {
        // Tenant-scoped through the model, like every query in this module.
        // A deposit's own movements are never candidates: they follow the deposit, and a funding
        // transfer that happens to match a typed payment is not the same money twice.
        $buckets = FinancialTransaction::query()
            ->where('source', '!=', RecordSource::SYSTEM->value)
            ->select(['booked_at', 'amount_minor', 'currency_code'])
            ->groupBy(['booked_at', 'amount_minor', 'currency_code'])
            ->havingRaw('count(*) > 1')
            ->havingRaw('count(*) filter (where duplicate_reviewed_at is null) > 0')
            ->orderByDesc('booked_at')
            ->limit(self::MAX_CANDIDATE_DAYS)
            ->toBase()
            ->get();

        if ($buckets->isEmpty()) {
            return [];
        }

        $members = FinancialTransaction::query()
            ->where('source', '!=', RecordSource::SYSTEM->value)
            ->where(static function ($query) use ($buckets): void {
                foreach ($buckets as $bucket) {
                    $query->orWhere(static function ($inner) use ($bucket): void {
                        $inner->where('booked_at', $bucket->booked_at)
                            ->where('amount_minor', $bucket->amount_minor)
                            ->where('currency_code', $bucket->currency_code);
                    });
                }
            })
            ->orderByDesc('booked_at')
            ->orderBy('id')
            ->get();

        $byFigures = [];

        foreach ($members as $transaction) {
            $key = $transaction->booked_at->toDateString() . '|' . $transaction->amount_minor . '|' . $transaction->currency_code;
            $byFigures[$key][] = $transaction;
        }

        return array_values($byFigures);
    }

    /**
     * Rows within one bucket that could name the same party, as connected groups.
     *
     * Connected rather than pairwise: a receipt naming "Kaufland", a statement row naming "KAUFLAND
     * 1234" and a typed row naming nobody are one payment even though the receipt and the statement
     * row are only linked through each other's spelling.
     *
     * @param  list<FinancialTransaction>  $rows
     * @return list<list<FinancialTransaction>>
     */
    private function clusters(array $rows): array
    {
        $count = count($rows);
        $seen = [];
        $clusters = [];

        for ($start = 0; $start < $count; $start++) {
            if (isset($seen[$start])) {
                continue;
            }

            $seen[$start] = true;
            $pending = [$start];
            $cluster = [];

            while ($pending !== []) {
                $current = array_pop($pending);
                $cluster[] = $rows[$current];

                for ($other = 0; $other < $count; $other++) {
                    if (! isset($seen[$other]) && $this->sameCounterparty($rows[$current], $rows[$other])) {
                        $seen[$other] = true;
                        $pending[] = $other;
                    }
                }
            }

            $clusters[] = $cluster;
        }

        return $clusters;
    }

    private function sameCounterparty(FinancialTransaction $first, FinancialTransaction $second): bool
    {
        $firstName = trim((string) $first->counterparty_ciphertext);
        $secondName = trim((string) $second->counterparty_ciphertext);

        if ($firstName === '' || $secondName === '') {
            return true;
        }

        return PartyName::sharesName($firstName, $secondName);
    }

    /**
     * @param  list<FinancialTransaction>  $cluster
     */
    private function hasUnreviewed(array $cluster): bool
    {
        foreach ($cluster as $transaction) {
            if ($transaction->getAttribute('duplicate_reviewed_at') === null) {
                return true;
            }
        }

        return false;
    }
}
