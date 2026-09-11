<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\UpdateFinancialTransaction;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /api/v1/subjects/{subject}/wallet/transactions/{transaction}`.
 *
 * `sometimes` throughout: an absent key is unchanged. `account_id` and `currency_code` are not
 * accepted — moving a transaction between accounts changes two balances at once, and the honest
 * correction is to delete it and record it where it belongs.
 */
final class UpdateTransactionController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $transaction,
        UpdateFinancialTransaction $update,
    ): JsonResponse {
        $model = FinancialTransaction::query()->findOrFail($transaction);

        $validated = $request->validate([
            // Signed minor units. Zero is refused here and again in the action, which importers and
            // document parsers also reach.
            'amount_minor' => ['sometimes', 'integer', 'not_in:0'],
            'booked_at' => ['sometimes', 'date'],
            'value_at' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'string', 'max:500'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $update(
            transaction: $model,
            amountMinor: array_key_exists('amount_minor', $validated) ? (int) $validated['amount_minor'] : null,
            bookedAt: $this->optional($validated, 'booked_at'),
            // Present-but-null clears the value date; absent leaves it alone.
            valueAt: array_key_exists('value_at', $validated) ? (string) ($validated['value_at'] ?? '') : null,
            description: $this->optional($validated, 'description'),
            counterparty: array_key_exists('counterparty', $validated)
                ? (string) ($validated['counterparty'] ?? '')
                : null,
            actorId: $this->actorId($request),
        );

        return response()->json(['id' => $model->id]);
    }

    /** @param array<string, mixed> $validated */
    private function optional(array $validated, string $key): ?string
    {
        if (! array_key_exists($key, $validated)) {
            return null;
        }

        $value = $validated[$key];

        return is_scalar($value) ? (string) $value : null;
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
