<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Routing\PageSizes;
use Kern\TaxRomania\Wallet\Queries\CounterpartyList;
use Kern\TaxRomania\Wallet\Services\TagDirectory;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;

/**
 * `GET .../wallet/counterparties` — the parties this taxpayer has dealt with, busiest first.
 *
 * The catalogue and the tags travel with the page because the screen edits a party's rule in place:
 * fetching them separately would make the first edit wait for a second round trip, and they are the
 * same two lists the classification dialog is built from.
 */
final class ShowCounterpartiesController
{
    /** Enough to merge every spelling of one party, few enough to be one screen's worth. */
    private const int SEARCH_LIMIT = 200;


    public function __invoke(
        Request $request,
        string $subject,
        CounterpartyList $counterparties,
        TaxCategoryCatalogue $categories,
        TagDirectory $tags,
        PageSizes $sizes,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => $sizes->rule(),
        ]);

        $search = is_string($validated['q'] ?? null) && $validated['q'] !== '' ? $validated['q'] : null;

        /*
         * A search is not paged.
         *
         * Browsing two and a half thousand parties needs pages; a search returns the handful that
         * match, and those are the ones a person is about to merge. Paged, a merge could only ever
         * reach the spellings that happened to land on the first page — which is the opposite of
         * what searching for them was for.
         *
         * Capped all the same: a two-letter search matches a great many parties, and the cap is what
         * stops that becoming a page of every counterparty the taxpayer has.
         */
        $page = $search === null && is_numeric($validated['page'] ?? null) ? (int) $validated['page'] : 1;
        $perPage = $search !== null
            ? self::SEARCH_LIMIT
            : $sizes->of($validated['per_page'] ?? null);

        return response()->json([
            ...$counterparties->page($search, $page, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'categories' => $categories->toArray(),
            'tags' => $tags->all(),
        ]);
    }
}
