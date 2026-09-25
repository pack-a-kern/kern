<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;

/**
 * `GET /api/v1/subjects/{subject}/wallet/categories` — what a transaction can be said to be.
 *
 * The transactions list already carries this, but a screen that only records one transaction — the
 * document import dialog — should not fetch a hundred transactions to learn fourteen codes. Same
 * catalogue, same shape, so both screens offer exactly what the server will accept.
 */
final class ShowTaxCategoriesController
{
    public function __invoke(TaxCategoryCatalogue $categories): JsonResponse
    {
        return response()->json(['categories' => $categories->toArray()]);
    }
}
