<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Banks;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Banks\Logos\LogoCoverage;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowBanksController;

/**
 * `GET /api/v1/settings/banks/logos` — the logo folders that can be chosen, the one that is, and
 * which of the banks a person picks from still have none, with the file each would need.
 */
final class ShowBankLogosController
{
    public function __invoke(LogoCoverage $coverage): JsonResponse
    {
        return response()->json($coverage->of(ShowBanksController::OFFERED));
    }
}
