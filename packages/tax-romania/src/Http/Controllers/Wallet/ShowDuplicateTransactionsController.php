<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Wallet\Queries\DuplicateTransactionGroups;
use Kern\TaxRomania\Wallet\Queries\TransactionSummary;

/**
 * `GET /api/v1/subjects/{subject}/wallet/transactions/duplicates` — lookalike transactions, grouped,
 * for a person to merge or keep.
 *
 * Each group names the row a merge would keep, decided by the same rule the merge applies — so what
 * the screen marks as surviving is what survives.
 */
final class ShowDuplicateTransactionsController
{
    public function __invoke(DuplicateTransactionGroups $groups): JsonResponse
    {
        return response()->json([
            'groups' => array_map(
                static fn (array $group): array => [
                    'survivor_id' => $group['survivor_id'],
                    'transactions' => array_map(
                        static fn (TransactionSummary $transaction): array => $transaction->toArray(),
                        $group['transactions'],
                    ),
                ],
                $groups(),
            ),
        ]);
    }
}
