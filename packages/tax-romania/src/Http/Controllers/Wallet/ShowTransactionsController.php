<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Platform\Routing\PageSizes;
use Kern\TaxRomania\StatementImport\Queries\StatementLines;
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
    public function __invoke(
        Request $request,
        WalletQuery $wallet,
        TaxCategoryCatalogue $categories,
        StatementLines $statementLines,
        PageSizes $sizes,
    ): JsonResponse {
        $validated = $request->validate([
            'account_id' => ['sometimes', 'nullable', 'uuid'],
            'bank_id' => ['sometimes', 'nullable', 'uuid'],
            'tag_id' => ['sometimes', 'nullable', 'uuid'],
            // The worklist, or what has been answered. Anything else means "everything".
            'classification' => ['sometimes', 'nullable', Rule::enum(TransactionClassificationStatus::class)],
            // One tax category, named by code. Checked against the catalogue rather than taken as
            // given: an unknown code would silently return nothing, which reads as "no such
            // transactions" rather than as the typo it is.
            'category_code' => ['sometimes', 'nullable', 'string', Rule::in($categories->codes())],
            'direction' => ['sometimes', 'nullable', Rule::enum(MoneyDirection::class)],
            // Inclusive on both ends: a person filtering "up to 31 March" means that whole day.
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            // Typed into the search box above the ledger. Matched against the decrypted directory
            // here rather than in SQL: a fragment cannot be compared with a ciphertext or with the
            // hash of a whole name.
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => $sizes->rule(),
        ]);

        /*
         * Cast, not type-checked. The `integer` rule above *validates* that a value is an integer;
         * it does not convert one, and `validated()` hands back what the request held — which for a
         * query string is always a string. `is_int('2')` is false, so both of these silently fell
         * back to their defaults and every page of the ledger returned the first one. The per-page
         * default hid it: the screen asks for 25 and 25 is what it got.
         *
         * Every other paged endpoint in this application already casts. This one did not.
         */
        $perPage = $sizes->of($validated['per_page'] ?? null);
        $page = is_numeric($validated['page'] ?? null) ? (int) $validated['page'] : 1;

        /** @var array{account_id?: ?string, bank_id?: ?string, tag_id?: ?string, counterparty?: ?string, classification?: ?string, category_code?: ?string, direction?: ?string, from?: ?string, to?: ?string} $filters */
        $filters = array_intersect_key($validated, array_flip([
            'account_id', 'bank_id', 'tag_id', 'counterparty', 'classification', 'category_code', 'direction', 'from', 'to',
        ]));

        $result = $wallet->transactions($filters, $page, $perPage);

        /*
         * The statement line each row was made from, attached here rather than inside `WalletQuery`.
         *
         * `Wallet` may not reach into `StatementImport`, and does not — the two meet in the HTTP
         * layer, which is where the account controllers already turn a `bank_id` into a bank's name
         * through `Banks\Queries\BankDirectory`. A controller is not a module, which is why
         * `deptrac` has nothing to say about either.
         *
         * Fetched for the whole page rather than per row opened: a line is four encrypted columns,
         * so twenty-five of them is around three milliseconds against a round trip each.
         */
        $lines = $statementLines->forTransactions(array_map(
            static fn (TransactionSummary $transaction): string => $transaction->id,
            $result['transactions'],
        ));

        return response()->json([
            'transactions' => array_map(
                static fn (TransactionSummary $transaction): array => [
                    ...$transaction->toArray(),
                    // Null for a transaction somebody typed: there is no statement behind it.
                    'source_line' => $lines[$transaction->id] ?? null,
                ],
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
