<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Queries\DuplicateTransactionGroups;
use App\Domain\Wallet\Queries\TransactionSummary;
use Illuminate\Http\JsonResponse;

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
