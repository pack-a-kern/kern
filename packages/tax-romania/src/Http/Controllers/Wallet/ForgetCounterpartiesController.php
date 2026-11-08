<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Wallet\Actions\ForgetCounterparties;
use Kern\TaxRomania\Wallet\Models\Counterparty;

/**
 * `POST .../wallet/counterparties/forget` — forget what was decided about these parties, or, with
 * `all`, about every party this taxpayer has.
 *
 * A POST rather than a DELETE because it names several in a body, and because what it removes is a
 * set of decisions rather than one addressable thing. The transactions are untouched; the response
 * says how many rules went, which is the only number a person can check this against.
 */
final class ForgetCounterpartiesController
{
    public function __invoke(Request $request, string $subject, ForgetCounterparties $forget): JsonResponse
    {
        $validated = $request->validate([
            'all' => ['sometimes', 'boolean'],
            'counterparty_ids' => ['required_unless:all,true', 'prohibited_if:all,true', 'array', 'min:1'],
            'counterparty_ids.*' => ['uuid'],
        ]);

        // Tenant-scoped: another subject's party is not found rather than forgotten.
        if ($request->boolean('all')) {
            $parties = Counterparty::query()->get(['id', 'tax_subject_id'])->all();
        } else {
            /** @var list<string> $ids */
            $ids = array_values(array_map(
                static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
                is_array($validated['counterparty_ids'] ?? null) ? $validated['counterparty_ids'] : [],
            ));

            $parties = Counterparty::query()->whereIn('id', $ids)->get(['id', 'tax_subject_id'])->all();
        }

        if ($parties === []) {
            return response()->json(['counterparties_forgotten' => 0]);
        }

        /** @var non-empty-list<Counterparty> $parties */
        return response()->json(['counterparties_forgotten' => $forget($parties, $this->actorId($request))]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
