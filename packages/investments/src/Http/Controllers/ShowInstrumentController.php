<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;

/** `GET /api/v1/investments/instruments/{id}` — its terms, every payout in date order, and its latest price. */
final class ShowInstrumentController
{
    public function __invoke(string $instrument): JsonResponse
    {
        $found = FinancialInstrument::query()->findOrFail($instrument);
        $price = InstrumentPrice::query()->where('instrument_id', $found->id)->orderByDesc('trading_date')->first();

        $found->setAttribute('latest_price', $price?->price);
        $found->setAttribute('latest_trading_date', $price?->trading_date->format('Y-m-d'));

        return response()->json([
            ...MarketViews::instrument($found),
            'payouts' => InstrumentPayout::query()->where('instrument_id', $found->id)->orderBy('payment_date')->get()->map(MarketViews::payout(...))->all(),
        ]);
    }
}
