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
 * `GET /api/v1/investments/instruments/{id}/prices?from=…&to=…` — the price on each trading day of a span,
 * oldest first. The span is bounded by configuration, so one answer is never the whole history at once.
 */
final class ListInstrumentPricesController
{
    public function __invoke(Request $request, string $instrument, InvestmentsConfig $config): JsonResponse
    {
        $span = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (Carbon::parse($span['from'])->diffInDays(Carbon::parse($span['to'])) > $config->api->maxSpanDays) {
            throw ValidationException::withMessages(['to' => "The span may be {$config->api->maxSpanDays} days at most."]);
        }

        $found = FinancialInstrument::query()->findOrFail($instrument);

        return response()->json([
            'data' => InstrumentPrice::query()
                ->where('instrument_id', $found->id)
                ->whereBetween('trading_date', [$span['from'], $span['to']])
                ->orderBy('trading_date')
                ->get()
                ->map(MarketViews::price(...))
                ->all(),
        ]);
    }
}
