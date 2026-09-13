<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Actions\RecordFinancialTransaction;
use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/{subject}/wallet/transactions` — record one transaction by hand.
 *
 * `source` is `MANUAL`, and that is the whole story of this endpoint: `financial_transactions`
 * carries `import_batch_id` and a blind-indexed `external_id` because the schema was built for
 * *bank statement import*, where a batch groups one statement and the external id lets a re-import
 * recognise rows it already holds instead of duplicating them. None of that machinery exists yet,
 * so this is the manual path, and it deliberately sets neither field.
 *
 * Why transactions matter beyond bookkeeping: `App\Domain\Rental\Services\Matching\
 * TransactionMatchCandidateFinder` reads this table to answer "is this 2,500 RON the rent for
 * Apt 12?". Without them, rental income is asserted; with them, it is matched against money that
 * actually arrived.
 *
 * Splits are accepted by the action (one transaction divided across categories) but not offered
 * here — the screen has nowhere to express them yet, and an endpoint that took them would be
 * accepting input nothing can produce.
 */
final class RecordTransactionController extends Controller
{
    public function __invoke(
        Request $request,
        RecordFinancialTransaction $record,
        TenantContext $tenant,
    ): JsonResponse {
        $validated = $request->validate([
            // Only a source that receives transactions. A cash holding is one declared value and a
            // deposit's entries are computed from its terms, so posting into either detaches the
            // ledger from the thing it describes. Rejected here as a field error rather than left
            // to `RecordFinancialTransaction`'s own guard, which is the backstop for an importer
            // that never passed through a form.
            'account_id' => ['required', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                $account = FinancialAccount::query()->find(is_string($value) ? $value : '');

                if ($account !== null && $account->kind->isHolding()) {
                    $fail("A {$account->kind->value} source does not receive transactions.");
                }
            }],
            // Signed minor units: money out is negative, money in positive. A separate
            // "direction" field would let the sign and the direction disagree.
            'amount_minor' => ['required', 'integer', 'not_in:0'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'booked_at' => ['required', 'date'],
            // When the money actually moved, if that differs from when the bank booked it. Only
            // `value_at` is meaningful for an accrual question; `booked_at` is the statement's view.
            'value_at' => ['sometimes', 'nullable', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Tenant-scoped lookup: an account id belonging to another subject 404s here rather than
        // being written as a foreign key.
        FinancialAccount::query()->findOrFail((string) $validated['account_id']);

        $transaction = $record(new RecordFinancialTransactionInput(
            taxSubjectId: $tenant->currentSubjectId(),
            accountId: (string) $validated['account_id'],
            bookedAt: (string) $validated['booked_at'],
            amountMinor: (int) $validated['amount_minor'],
            currencyCode: (string) $validated['currency_code'],
            description: (string) $validated['description'],
            // Encrypted at rest like the description: a counterparty name is personal data about
            // someone who never used this application.
            counterparty: is_string($validated['counterparty'] ?? null) ? $validated['counterparty'] : '',
            valueAt: is_string($validated['value_at'] ?? null) ? $validated['value_at'] : null,
            source: RecordSource::MANUAL,
            actorId: $this->actorId($request),
        ));

        $key = $transaction->getKey();

        return response()->json(['id' => is_scalar($key) ? (string) $key : ''], 201);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
