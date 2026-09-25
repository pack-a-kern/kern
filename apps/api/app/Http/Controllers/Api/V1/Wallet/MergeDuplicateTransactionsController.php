<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Documents\Actions\MergeDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Wallet\Actions\MergeDuplicateTransactions;
use App\Domain\Wallet\Exceptions\NotDuplicateTransactionsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/subjects/{subject}/wallet/transactions/duplicates/merge` — combine lookalike
 * transactions into one, and their documents into one.
 *
 * ## Why the two halves meet here
 *
 * `Wallet` may not depend on `Documents` and neither may the reverse (`deptrac.php`), but a merge is
 * one decision about both: the discarded rows go, and the evidence they carried becomes further pages
 * of the survivor's document. This controller is the one place that already speaks to both modules,
 * so it runs the two actions in one transaction — a merge that deleted the rows and then failed to
 * move their documents would leave evidence attached to nothing.
 */
final class MergeDuplicateTransactionsController
{
    public function __invoke(
        Request $request,
        MergeDuplicateTransactions $mergeTransactions,
        MergeDocuments $mergeDocuments,
    ): JsonResponse {
        $ids = $this->transactionIds($request);
        $actorId = $this->actorId($request);

        try {
            $survivorId = DB::transaction(function () use ($ids, $actorId, $mergeTransactions, $mergeDocuments): string {
                $merged = $mergeTransactions($ids, $actorId);

                if ($merged->survivorDocumentId !== null && $merged->absorbedDocumentIds !== []) {
                    // `find`, not `findOrFail`: a survivor whose document was soft-deleted still
                    // merges its rows. There is simply no document left to fold the others into.
                    $document = Document::query()->find($merged->survivorDocumentId);

                    if ($document !== null) {
                        $mergeDocuments($document, $merged->absorbedDocumentIds, $actorId);
                    }
                }

                return $merged->survivorId;
            });
        } catch (NotDuplicateTransactionsException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'NOT_DUPLICATES',
            ], 422);
        }

        return response()->json(['survivor_id' => $survivorId]);
    }

    /**
     * @return list<string>
     */
    private function transactionIds(Request $request): array
    {
        $validated = $request->validate([
            // Bounded: a merge deletes rows, and no real set of lookalikes is fifty long.
            'transaction_ids' => ['required', 'array', 'min:2', 'max:50'],
            'transaction_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $ids = $validated['transaction_ids'] ?? [];

        return array_values(array_filter(is_array($ids) ? $ids : [], is_string(...)));
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
