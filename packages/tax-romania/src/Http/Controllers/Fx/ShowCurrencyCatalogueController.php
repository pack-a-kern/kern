<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Fx;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Fx\Services\CurrencyCatalogue;

/**
 * `GET /v1/fx/currencies` — the currencies this installation works in, and the ones it could.
 *
 * For the settings screen, which offers `available` and saves a subset of it. The forms do not call
 * this: they are handed `enabled` with the page they arrived on, since every screen that records an
 * amount needs it and none of them should wait on a request for five strings.
 */
final class ShowCurrencyCatalogueController
{
    public function __invoke(CurrencyCatalogue $catalogue): JsonResponse
    {
        return response()->json([
            'enabled' => $catalogue->enabled(),
            'available' => $catalogue->available(),
        ]);
    }
}
