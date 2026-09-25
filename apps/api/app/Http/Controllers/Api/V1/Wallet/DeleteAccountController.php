<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\DeleteFinancialAccount;
use App\Domain\Wallet\Exceptions\AccountNotEmptyException;
use App\Domain\Wallet\Exceptions\UndeletableSourceException;
use App\Domain\Wallet\Models\FinancialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/subjects/{subject}/wallet/accounts/{account}`.
 *
 * Answers `409 Conflict` — not `422` — when transactions or interest events still reference the
 * account: the request is perfectly well formed, it is the state of the world that forbids it, and
 * the screen's response is to offer *closing* instead, not to correct a field. The counts come back
 * in the body so it can say how much would have been destroyed.
 */
final class DeleteAccountController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $account,
        DeleteFinancialAccount $delete,
    ): JsonResponse {
        // Tenant-scoped: another subject's account is not found rather than deleted.
        $model = FinancialAccount::query()->findOrFail($account);

        try {
            $delete($model, $this->actorId($request));
        } catch (UndeletableSourceException $exception) {
            // 422 rather than 409: nothing is in the way that could be cleared, the source simply
            // is not a thing that can be removed.
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'GENERAL_SOURCE_UNDELETABLE',
            ], 422);
        } catch (AccountNotEmptyException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'SOURCE_NOT_EMPTY',
                'transactions' => $exception->transactionCount,
                'interest_events' => $exception->interestEventCount,
            ], 409);
        }

        return response()->json([], 204);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
