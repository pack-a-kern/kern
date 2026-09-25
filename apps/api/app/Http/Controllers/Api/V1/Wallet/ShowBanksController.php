<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Banks\DataTransferObjects\BankOption;
use App\Domain\Banks\Queries\BankDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/subjects/{subject}/wallet/banks` — the banks a deposit can be opened at, or with
 * `?include_closed=1` every bank, for a statement or a transaction from a bank that has since closed.
 *
 * The same list for every taxpayer, imported from the ECB by the "Import banks" task. Under the
 * subject's routes anyway, because the deposit form that reads it is.
 */
final class ShowBanksController
{
    public function __invoke(Request $request, BankDirectory $banks): JsonResponse
    {
        return response()->json([
            'banks' => array_map(
                static fn (BankOption $bank): array => $bank->toArray(),
                $request->boolean('include_closed') ? $banks->all() : $banks->active(),
            ),
        ]);
    }
}
