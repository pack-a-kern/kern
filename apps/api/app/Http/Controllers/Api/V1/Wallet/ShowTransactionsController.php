<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Queries\TransactionSummary;
use App\Domain\Wallet\Queries\WalletQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/subjects/{subject}/wallet/transactions` — recent activity, newest first.
 *
 * Capped rather than paged, and the cap is reported. This screen answers "what has been happening",
 * which a window serves; someone reconciling a whole tax year needs filters and a date range, and
 * building a pager now would suggest it already does that.
 */
final class ShowTransactionsController extends Controller
{
    private const int DEFAULT_LIMIT = 100;

    private const int MAX_LIMIT = 250;

    public function __invoke(Request $request, WalletQuery $wallet): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['sometimes', 'nullable', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $limit = isset($validated['limit']) && is_int($validated['limit'])
            ? $validated['limit']
            : self::DEFAULT_LIMIT;

        $transactions = $wallet->transactions(
            accountId: is_string($validated['account_id'] ?? null) ? $validated['account_id'] : null,
            limit: $limit,
        );

        return response()->json([
            'transactions' => array_map(
                static fn (TransactionSummary $transaction): array => $transaction->toArray(),
                $transactions,
            ),
            // So the screen can say it is showing a window rather than everything.
            'limit' => $limit,
            'capped' => count($transactions) === $limit,
        ]);
    }
}
