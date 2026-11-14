<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Queries\InstrumentPayouts;

/**
 * `GET /api/v1/investments/instruments/{id}/payouts` — the payments its screen lists, in date order: a
 * share's dividends; a bond's coupons, each numbered as its terms count them.
 */
final class ListInstrumentPayoutsController
{
    public function __invoke(string $instrument): JsonResponse
    {
        $found = FinancialInstrument::query()->findOrFail($instrument);
        $numbered = $found->kind !== InstrumentKind::SHARE;

        return response()->json([
            'data' => InstrumentPayouts::shown($found)->get()->values()->map(
                static fn (InstrumentPayout $payout, int $at): array => [...MarketViews::payout($payout), 'number' => $numbered ? $at + 1 : null],
            )->all(),
        ]);
    }
}
