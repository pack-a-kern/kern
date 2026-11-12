<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;

/**
 * `GET .../wallet/counterparties/suggestions?q=` — names this taxpayer has used that hold what is being
 * typed, for the counterparty box: names only, a shortlist, the one typed left out.
 *
 * Not the counterparties screen's endpoint: that one carries each party's rules, the category catalogue
 * and the tags, for editing a party in place — none of which a suggestion needs.
 */
final class ShowCounterpartySuggestionsController
{
    /** A shortlist to pick from while typing, not a list to read. */
    private const int SHOWN = 10;

    public function __invoke(Request $request, string $subject, CounterpartyDirectory $directory): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);
        $typed = Normalizer::text((string) $validated['q']);

        // One more than shown, so the name already typed can be left out and the list still be full.
        $names = array_values(array_filter(
            array_column($directory->search((string) $validated['q'], self::SHOWN + 1), 'name'),
            static fn (string $name): bool => Normalizer::text($name) !== $typed,
        ));

        return response()->json(['names' => array_slice($names, 0, self::SHOWN)]);
    }
}
