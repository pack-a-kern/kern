<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Enums\Market;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Investments\Models\InstrumentPrice;
use Kern\Investments\Queries\InstrumentPayouts;

/**
 * `GET /api/v1/investments/instruments/{id}` — its terms, its latest price and how it moved, the order its
 * terms were read from, and of its payments how many its screen lists, the next and the last paid. The
 * payments themselves are their own endpoint, asked for when opened.
 */
final class ShowInstrumentController
{
    public function __invoke(string $instrument, InvestmentsConfig $config): JsonResponse
    {
        $found = FinancialInstrument::query()->with('issuerProfile')->findOrFail($instrument);
        $price = InstrumentPrice::query()->where('instrument_id', $found->id)->orderByDesc('trading_date')->first();
        // The application's clock, as the import's: a payment is next from its own day on.
        $today = Carbon::today('UTC')->format('Y-m-d');
        $payout = static fn (?InstrumentPayout $one): ?array => $one === null ? null : MarketViews::payout($one);

        $found->setAttribute('latest_price', $price?->price);
        $found->setAttribute('latest_trading_date', $price?->trading_date->format('Y-m-d'));

        // A share or a listed bond has its own page on BVB, built from its symbol; a Treasury bond never
        // listed there has none.
        $bvbUrl = $found->market === Market::BVB && $found->symbol !== null ? $config->bvb->instrumentUrl($found->symbol) : null;

        return response()->json([
            ...MarketViews::instrument($found),
            'latestChange' => MarketViews::change($price?->change_percent),
            'bvbUrl' => $bvbUrl,
            'termsDocumentId' => $found->terms_document_id,
            'payoutsCount' => InstrumentPayouts::shown($found)->count(),
            'nextPayout' => $payout(InstrumentPayouts::shown($found)->where('payment_date', '>=', $today)->first()),
            'lastPayout' => $payout(InstrumentPayouts::shown($found)->where('payment_date', '<', $today)->reorder('payment_date', 'desc')->orderByDesc('id')->first()),
        ]);
    }
}
