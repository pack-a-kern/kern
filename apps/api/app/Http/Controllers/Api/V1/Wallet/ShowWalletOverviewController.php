<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Queries\AccountSummary;
use App\Domain\Wallet\Queries\DepositSummary;
use App\Domain\Wallet\Queries\WalletQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/subjects/{subject}/wallet/overview` — the wallet landing screen.
 *
 * Returns totals plus the two short lists the screen leads with (accounts, and deposits maturing
 * soonest) rather than making the client fetch three endpoints and assemble them. The full listings
 * live behind their own endpoints, so this one is deliberately capped.
 *
 * Totals are **per currency**. There is no single "total balance" here, and that is a correctness
 * decision rather than an omission: summing RON and EUR needs a rate, a date and a source, which is
 * `FxConversionPolicy`'s job. A dashboard that produced one number would be inventing an exchange
 * rate and presenting it as fact.
 */
final class ShowWalletOverviewController extends Controller
{
    /** Enough to see the shape of things; the full lists have their own screens. */
    private const int PREVIEW_LIMIT = 4;

    public function __invoke(WalletQuery $wallet): JsonResponse
    {
        $accounts = $wallet->accounts();
        $maturing = $wallet->deposits(includeMatured: false);

        return response()->json([
            'totals' => $wallet->totals(),
            'accounts' => array_map(
                static fn (AccountSummary $account): array => $account->toArray(),
                array_slice($accounts, 0, self::PREVIEW_LIMIT),
            ),
            'accounts_total' => count($accounts),
            'maturing' => array_map(
                static fn (DepositSummary $deposit): array => $deposit->toArray(),
                array_slice($maturing, 0, self::PREVIEW_LIMIT),
            ),
            'maturing_total' => count($maturing),
        ]);
    }
}
