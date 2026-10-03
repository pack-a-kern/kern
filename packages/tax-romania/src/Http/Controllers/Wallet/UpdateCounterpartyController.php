<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Wallet\Actions\UpdateCounterparty;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Services\TagDirectory;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;

/**
 * `PATCH .../wallet/counterparties/{counterparty}` — what this party is, for one direction.
 *
 * The response says how many transactions took the answer, because that is the part a person cannot
 * see: the row they were looking at was already going to change, and the other two hundred are the
 * reason the rule exists.
 */
final class UpdateCounterpartyController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $counterparty,
        UpdateCounterparty $update,
        TaxCategoryCatalogue $categories,
        TagDirectory $tags,
    ): JsonResponse {
        // Tenant-scoped: another subject's party is not found rather than rewritten.
        $model = Counterparty::query()->findOrFail($counterparty);

        $validated = $request->validate([
            'category_code' => ['present', 'nullable', 'string', Rule::in($categories->codes())],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            // Names, not ids — the same shape the classify dialog posts, so a tag the taxpayer has
            // not used before is created by naming it instead of needing a round trip first.
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:' . TagDirectory::MAX_LENGTH],
            // Sent by the parties screen once the person has been told how many answers it would
            // overwrite. Absent means the safe reading: fill what has no answer.
            'replace' => ['sometimes', 'boolean'],
        ]);

        /** @var list<string> $names */
        $names = array_values(array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            is_array($validated['tags'] ?? null) ? $validated['tags'] : [],
        ));

        // Finds the ones this taxpayer already has and writes down the ones they do not, which is
        // what makes a tag typed here the same tag typed on a transaction.
        $known = $tags->idsFor($names);

        $applied = $update(
            $model,
            is_string($validated['category_code'] ?? null) ? $validated['category_code'] : null,
            is_string($validated['country_code'] ?? null) ? $validated['country_code'] : null,
            $known,
            ($validated['replace'] ?? false) === true,
            $this->actorId($request),
        );

        return response()->json(['id' => $model->id, 'transactions_updated' => $applied]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
