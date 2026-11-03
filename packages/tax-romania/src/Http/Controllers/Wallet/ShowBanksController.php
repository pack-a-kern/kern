<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Banks\DataTransferObjects\BankOption;
use Kern\TaxRomania\Banks\Enums\BankKind;
use Kern\TaxRomania\Banks\Queries\BankDirectory;

/**
 * `GET /api/v1/subjects/{subject}/wallet/banks` — the banks a deposit can be opened at, or with
 * `?include_closed=1` every bank, for a statement or a transaction from a bank that has since closed.
 *
 * The same list for every taxpayer, imported from the ECB by the "Import banks" task. Under the
 * subject's routes anyway, because the deposit form that reads it is.
 */
final class ShowBanksController
{
    /**
     * The kinds a person picks from, in both lists.
     *
     * The register holds more than the places somebody banks. Its forty credit cooperatives are each
     * a separate institution with its own members, and offering all of them turns a select of a few
     * dozen usable banks into one of eighty-seven, most of which no taxpayer here will ever hold an
     * account at. `UNKNOWN` is in because it does not mean "not a bank": it means the registration
     * number matched nothing in `config/banks.php`, which today includes Banca de Investiții și
     * Dezvoltare — a real, active bank registered under letters no regulation we have read defines.
     * Leaving it out would hide a working bank behind a gap in our own table.
     *
     * Stated here rather than taken from the request: which institutions a person may name is this
     * application's answer, not a caller's.
     *
     * @var list<BankKind>
     */
    public const array OFFERED = [BankKind::BANK, BankKind::FOREIGN_BRANCH, BankKind::UNKNOWN];

    public function __invoke(Request $request, BankDirectory $banks): JsonResponse
    {
        return response()->json([
            'banks' => array_map(
                static fn (BankOption $bank): array => $bank->toArray(),
                $request->boolean('include_closed')
                    ? $banks->all(self::OFFERED)
                    : $banks->active(self::OFFERED),
            ),
        ]);
    }
}
