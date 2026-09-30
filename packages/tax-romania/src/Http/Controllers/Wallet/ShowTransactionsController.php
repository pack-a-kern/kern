<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Queries\TransactionSummary;
use Kern\TaxRomania\Wallet\Queries\WalletQuery;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;

/**
 * `GET /api/v1/subjects/{subject}/wallet/transactions` — the ledger, narrowed and paged.
 *
 * ## Why the server does the narrowing
 *
 * This used to answer with one window — the newest 100 rows, at most 250 — which the screen then
 * filtered in the browser. That was honest while every transaction was typed by hand. An imported
 * statement is several hundred rows on its own, and a filter over a window would answer "nothing in
 * March" about a March that had simply fallen off the end of it.
 *
 * So every filter is a `where` and the page is a page: the counts under the pager are counts of what
 * matches, not of what happened to be fetched.
 */
final class ShowTransactionsController
{
    private const int DEFAULT_PER_PAGE = 25;

    private const int MAX_PER_PAGE = 250;

    public function __invoke(
        Request $request,
        WalletQuery $wallet,
        TaxCategoryCatalogue $categories,
    ): JsonResponse {
        $validated = $request->validate([
            'account_id' => ['sometimes', 'nullable', 'uuid'],
            'bank_id' => ['sometimes', 'nullable', 'uuid'],
            'tag_id' => ['sometimes', 'nullable', 'uuid'],
            // The worklist, or what has been answered. Anything else means "everything".
            'classification' => ['sometimes', 'nullable', Rule::enum(TransactionClassificationStatus::class)],
            'direction' => ['sometimes', 'nullable', Rule::enum(MoneyDirection::class)],
            // Inclusive on both ends: a person filtering "up to 31 March" means that whole day.
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        $perPage = isset($validated['per_page']) && is_int($validated['per_page'])
            ? $validated['per_page']
            : self::DEFAULT_PER_PAGE;
        $page = isset($validated['page']) && is_int($validated['page']) ? $validated['page'] : 1;

        /** @var array{account_id?: ?string, bank_id?: ?string, tag_id?: ?string, classification?: ?string, direction?: ?string, from?: ?string, to?: ?string} $filters */
        $filters = array_intersect_key($validated, array_flip([
            'account_id', 'bank_id', 'tag_id', 'classification', 'direction', 'from', 'to',
        ]));

        $result = $wallet->transactions($filters, $page, $perPage);

        return response()->json([
            'transactions' => array_map(
                static fn (TransactionSummary $transaction): array => $transaction->toArray(),
                $result['transactions'],
            ),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            // The vocabulary the classification dialog offers, from the same catalogue the
            // classify endpoint validates against — so the dropdown cannot offer a code the
            // server would refuse.
            'categories' => $categories->toArray(),
            // What the two filters above the ledger should offer: the banks and the sources
            // reachable under the other filters in force, rather than the whole register and every
            // account the taxpayer has. See `WalletQuery::reachable()`.
            'bank_ids_in_use' => $wallet->bankIdsInUse($filters),
            'account_ids_in_use' => $wallet->accountIdsInUse($filters),
        ]);
    }
}
