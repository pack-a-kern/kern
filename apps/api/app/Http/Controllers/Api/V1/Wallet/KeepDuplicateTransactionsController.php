<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\KeepDuplicateTransactions;
use App\Domain\Wallet\Exceptions\NotDuplicateTransactionsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/{subject}/wallet/transactions/duplicates/keep` — these lookalikes are
 * genuinely separate payments; stop asking.
 */
final class KeepDuplicateTransactionsController
{
    public function __invoke(Request $request, KeepDuplicateTransactions $keep): JsonResponse
    {
        $validated = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:2', 'max:50'],
            'transaction_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $ids = $validated['transaction_ids'] ?? [];

        try {
            $keep(array_values(array_filter(is_array($ids) ? $ids : [], is_string(...))), $this->actorId($request));
        } catch (NotDuplicateTransactionsException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'NOT_DUPLICATES',
            ], 422);
        }

        return response()->json([], 204);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
