<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Actions\RecordFinancialTransaction;
use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\DataTransferObjects\TransactionSplitInput;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Exceptions\CategoryDirectionException;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Services\TagDirectory;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use App\Http\Controllers\Api\V1\Wallet\Concerns\RespondsToCategoryDirection;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/subjects/{subject}/wallet/transactions` — record one transaction by hand.
 *
 * `source` is `MANUAL`, and that is the whole story of this endpoint: `financial_transactions`
 * carries `import_batch_id` and a blind-indexed `external_id` because the schema was built for
 * *bank statement import*, where a batch groups one statement and the external id lets a re-import
 * recognise rows it already holds instead of duplicating them. None of that machinery exists yet,
 * so this is the manual path, and it deliberately sets neither field.
 *
 * Splits are accepted by the action (one transaction divided across categories) but not offered
 * here — the screen has nowhere to express them yet, and an endpoint that took them would be
 * accepting input nothing can produce.
 */
final class RecordTransactionController extends Controller
{
    use RespondsToCategoryDirection;

    public function __invoke(
        Request $request,
        RecordFinancialTransaction $record,
        TenantContext $tenant,
        TaxCategoryCatalogue $categories,
        TagDirectory $tags,
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
            'description' => ['required', 'string', 'max:255'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:255'],

            // What the money was, answered while it is being typed. Someone entering a transaction
            // by hand already knows — asking again afterwards, in a separate dialog, is work for
            // nothing. Optional: a row can be recorded now and answered from the worklist later,
            // which is what imported and system-generated rows do.
            'category_code' => ['sometimes', 'nullable', 'string', Rule::in($categories->codes())],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            // Where it happened. A label on the transaction, never a second account — General stays
            // one pot, which is what the balance rules depend on.
            'bank_id' => ['sometimes', 'nullable', 'uuid'],
            // What it was *for*, in the person's own words. Names rather than ids, so a new tag is
            // made by typing it; the catalogue of existing ones is offered by the form.
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:' . TagDirectory::MAX_LENGTH],
        ]);

        // Tenant-scoped lookup: an account id belonging to another subject 404s here rather than
        // being written as a foreign key.
        FinancialAccount::query()->findOrFail((string) $validated['account_id']);

        // Answered while it is being typed, or left for the worklist.
        $category = $this->nullableString($validated, 'category_code');

        /** @var list<string> $tagNames */
        $tagNames = is_array($validated['tags'] ?? null) ? array_values(array_filter($validated['tags'], is_string(...))) : [];

        try {
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
                source: RecordSource::MANUAL,
                actorId: $this->actorId($request),
                bankId: $this->nullableString($validated, 'bank_id'),
                tagIds: $tags->idsFor($tagNames),
                splits: $category === null ? [] : [new TransactionSplitInput(
                    amountMinor: (int) $validated['amount_minor'],
                    categoryCode: $category,
                    countryCode: $this->nullableString($validated, 'country_code'),
                )],
            ));
        } catch (CategoryDirectionException $exception) {
            return $this->categoryDirection($exception);
        }

        $key = $transaction->getKey();

        return response()->json(['id' => is_scalar($key) ? (string) $key : ''], 201);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }

    /** @param array<string, mixed> $validated */
    private function nullableString(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
