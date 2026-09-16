<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\ClassifyTransaction;
use App\Domain\Wallet\DataTransferObjects\TransactionSplitInput;
use App\Domain\Wallet\Exceptions\CategoryDirectionException;
use App\Domain\Wallet\Exceptions\SystemTransactionException;
use App\Domain\Wallet\Exceptions\TransactionSplitMismatchException;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use App\Http\Controllers\Api\V1\Wallet\Concerns\RespondsToCategoryDirection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `PUT .../wallet/transactions/{transaction}/splits` — says what a transaction was.
 *
 * A `PUT` because it replaces: sending the splits again with one changed is how a correction is
 * made, and sending an empty list returns the transaction to the unclassified worklist. Appending
 * would leave a transaction claiming to be two contradictory things.
 *
 * The category is checked against `config/reference/tax_categories.php` through the same catalogue the screen
 * reads, so the dropdown can never offer a value the server refuses.
 */
final class ClassifyTransactionController extends Controller
{
    use RespondsToCategoryDirection;

    public function __invoke(
        Request $request,
        string $subject,
        string $transaction,
        ClassifyTransaction $classify,
        TaxCategoryCatalogue $categories,
    ): JsonResponse {
        // Tenant-scoped: another subject's transaction is not found rather than reclassified.
        $model = FinancialTransaction::query()->findOrFail($transaction);

        $validated = $request->validate([
            'splits' => ['present', 'array'],
            'splits.*.amount_minor' => ['required', 'integer'],
            'splits.*.category_code' => ['required', 'string', Rule::in($categories->codes())],
            // ISO 3166-1 alpha-2, and optional: most income is domestic, and a field answered the
            // same way every time stops being read. Null means Romania.
            'splits.*.country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $validated['splits'];

        try {
            $classify(
                $model,
                array_map(
                    static fn (array $row): TransactionSplitInput => new TransactionSplitInput(
                        amountMinor: is_numeric($row['amount_minor'] ?? null) ? (int) $row['amount_minor'] : 0,
                        categoryCode: is_string($row['category_code'] ?? null) ? $row['category_code'] : '',
                        countryCode: is_string($row['country_code'] ?? null) && $row['country_code'] !== ''
                            ? $row['country_code']
                            : null,
                    ),
                    $rows,
                ),
                $this->actorId($request),
            );
        } catch (SystemTransactionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'SYSTEM_TRANSACTION',
            ], 409);
        } catch (TransactionSplitMismatchException $exception) {
            // 422 with the two figures: splits partition a transaction rather than annotate it, and
            // the remedy is arithmetic the person can do once they can see both numbers.
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'SPLIT_TOTAL_MISMATCH',
            ], 422);
        } catch (CategoryDirectionException $exception) {
            return $this->categoryDirection($exception);
        }

        return response()->json([], 204);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
