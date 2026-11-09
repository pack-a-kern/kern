<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Wallet\Queries\Holdings;

/** `GET /api/v1/subjects/{subject}/wallet/holdings` — every lot of the taxpayer, valued as of today. */
final class ShowHoldingsController
{
    public function __invoke(Holdings $holdings): JsonResponse
    {
        return response()->json(['holdings' => $holdings->all()]);
    }
}
