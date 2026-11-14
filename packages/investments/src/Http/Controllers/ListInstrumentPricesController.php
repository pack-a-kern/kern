<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPrice;

/**
 * `GET /api/v1/investments/instruments/{id}/prices?months=…` — the price on each trading day of the last
 * so many months to today, oldest first. The span is bounded by configuration, so one answer is never the
 * whole history at once.
 */
final class ListInstrumentPricesController
{
    public function __invoke(Request $request, string $instrument, InvestmentsConfig $config): JsonResponse
    {
        $months = (int) $request->validate(['months' => ['required', 'integer', 'min:1']])['months'];
        // The application's clock, as the import's.
        $to = Carbon::today('UTC');
        $from = $to->copy()->subMonthsNoOverflow($months);

        if ($from->diffInDays($to) > $config->api->maxSpanDays) {
            throw ValidationException::withMessages(['months' => "The span may be {$config->api->maxSpanDays} days at most."]);
        }

        $found = FinancialInstrument::query()->findOrFail($instrument);

        return response()->json([
            'data' => InstrumentPrice::query()
                ->where('instrument_id', $found->id)
                // To the end of today: a day is stored with its midnight on one database, alone on the other.
                ->where('trading_date', '>=', $from->format('Y-m-d'))
                ->where('trading_date', '<', $to->copy()->addDay()->format('Y-m-d'))
                ->orderBy('trading_date')
                ->get()
                ->map(MarketViews::price(...))
                ->all(),
        ]);
    }
}
