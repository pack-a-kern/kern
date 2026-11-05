<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Banks;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Banks\Logos\BankLogos;
use Kern\TaxRomania\Banks\Logos\LogoCoverage;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowBanksController;

/**
 * `POST /api/v1/settings/banks/logos/refresh` — reads the chosen folder again, after files were
 * added or replaced, and answers what `ShowBankLogosController` would.
 *
 * Reads files and writes nothing but the cache: no bank, no setting and no file is changed.
 */
final class RefreshBankLogosController
{
    public function __invoke(BankLogos $logos, LogoCoverage $coverage): JsonResponse
    {
        $logos->refresh();

        return response()->json($coverage->of(ShowBanksController::OFFERED));
    }
}
