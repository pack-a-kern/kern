<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\DeleteFinancialTransaction;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/subjects/{subject}/wallet/transactions/{transaction}`.
 *
 * Unconditional, unlike deleting an account: a transaction's splits describe it and go with it, and
 * nothing computes a figure *through* a transaction the way it does through an account. The audit
 * entry keeps the amount and date, so a total that no longer adds up can still be explained.
 */
final class DeleteTransactionController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $transaction,
        DeleteFinancialTransaction $delete,
    ): JsonResponse {
        $model = FinancialTransaction::query()->findOrFail($transaction);

        $delete($model, $this->actorId($request));

        return response()->json([], 204);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
