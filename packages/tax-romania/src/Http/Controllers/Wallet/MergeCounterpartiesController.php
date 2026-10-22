<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Wallet\Actions\MergeCounterparties;
use Kern\TaxRomania\Wallet\Models\Counterparty;

/**
 * `POST .../wallet/counterparties/merge` — several spellings of one party, under one name.
 *
 * The surviving name is given rather than chosen from the ids: a person merging `Mega Oltenitei` and
 * `Mega Domn` usually wants neither, they want `Mega`. Sending a name covers both — one of the
 * existing spellings, or a new one.
 */
final class MergeCounterpartiesController
{
    public function __invoke(
        Request $request,
        string $subject,
        MergeCounterparties $merge,
    ): JsonResponse {
        $validated = $request->validate([
            'counterparty_ids' => ['required', 'array', 'min:1'],
            'counterparty_ids.*' => ['uuid'],
            // 255 to match the write path in `RecordTransactionController`, which is what a merged
            // name is written through. The three controllers that accept a counterparty name
            // currently cap it at 255, 200 and 120; see this change's handoff note.
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            is_array($validated['counterparty_ids']) ? $validated['counterparty_ids'] : [],
        ));

        // Tenant-scoped, and every id must resolve: a merge that silently skipped an unknown id
        // would leave the spelling it names behind, which is the one outcome the caller cannot see.
        $parties = Counterparty::query()->whereIn('id', $ids)->get()->all();

        if (count($parties) !== count(array_unique($ids))) {
            return response()->json([
                'message' => __('tax-romania::wallet.counterparty_not_found'),
                'reason' => 'COUNTERPARTY_NOT_FOUND',
            ], 404);
        }

        /** @var non-empty-list<Counterparty> $parties */
        $rewritten = $merge($parties, (string) $validated['name'], $this->actorId($request));

        return response()->json(['transactions_updated' => $rewritten]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
