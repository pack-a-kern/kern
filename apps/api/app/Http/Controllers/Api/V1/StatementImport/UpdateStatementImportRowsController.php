<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport;

use App\Domain\StatementImport\Actions\UpdateStatementImportRows;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\Wallet\Services\TagDirectory;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `PATCH .../wallet/imports/{import}/rows` — what a person decides about a set of rows.
 *
 * A set rather than a row, because that is how reviewing a statement is actually done: tick the
 * forty supermarket payments, give them all one category and a tag, move on. Only the keys sent are
 * changed, so "tag these" does not untick them.
 */
final class UpdateStatementImportRowsController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        UpdateStatementImportRows $update,
        TaxCategoryCatalogue $categories,
    ): JsonResponse {
        $model = StatementImport::query()->findOrFail($import);

        $validated = $request->validate([
            'row_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'row_ids.*' => ['uuid'],
            'included' => ['sometimes', 'boolean'],
            'category_code' => ['sometimes', 'nullable', 'string', Rule::in($categories->codes())],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:' . TagDirectory::MAX_LENGTH],
        ]);

        /** @var list<string> $rowIds */
        $rowIds = array_values(array_filter($validated['row_ids'], is_string(...)));

        /** @var array{included?: bool, category_code?: ?string, country_code?: ?string, tags?: list<string>} $changes */
        $changes = array_intersect_key($validated, array_flip(['included', 'category_code', 'country_code', 'tags']));

        try {
            $changed = $update($model, $rowIds, $changes);
        } catch (ImportNotOpenException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'IMPORT_NOT_OPEN',
            ], 409);
        }

        return response()->json(['changed' => $changed]);
    }
}
