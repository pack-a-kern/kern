<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Wallet\Queries\BankDeposits;
use Kern\TaxRomania\Wallet\Queries\DepositSummary;
use Kern\TaxRomania\Wallet\Queries\WalletQuery;

/**
 * `GET /api/v1/subjects/{subject}/wallet/overview` — the wallet landing screen.
 *
 * What the taxpayer has and where it is, the running deposits by bank, and the next few to mature.
 * The running deposits are read once and handed to each part, so the three cannot disagree about
 * which deposits are running.
 */
final class ShowWalletOverviewController
{
    /** Enough to see what matures next; the accounts screen lists them all. */
    private const int PREVIEW_LIMIT = 5;

    public function __invoke(WalletQuery $wallet): JsonResponse
    {
        $running = $wallet->deposits(includeClosed: false);

        return response()->json([
            'totals' => $wallet->totals($running),
            'banks' => array_map(static fn (BankDeposits $bank): array => $bank->toArray(), $wallet->depositsByBank($running)),
            'maturing' => array_map(
                static fn (DepositSummary $deposit): array => $deposit->toArray(),
                array_slice($running, 0, self::PREVIEW_LIMIT),
            ),
            'maturing_total' => count($running),
        ]);
    }
}
