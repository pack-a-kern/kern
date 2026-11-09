<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\InstrumentPayout;

/**
 * `GET /api/v1/investments/payouts/upcoming` and `…/recent` — coupons, repayments and dividends from today
 * on, soonest first, or before today, latest first; of one kind if asked. A page at a time by cursor,
 * ordered by the payment date and the id, both of which compare as text on either database.
 */
final class ListPayoutsController
{
    public function __invoke(Request $request, string $when, InvestmentsConfig $config): JsonResponse
    {
        $filters = $request->validate(['kind' => ['sometimes', Rule::enum(PayoutKind::class)]]);
        $today = Carbon::today()->format('Y-m-d');
        $upcoming = $when === 'upcoming';

        $page = InstrumentPayout::query()
            ->where('payment_date', $upcoming ? '>=' : '<', $today)
            ->when($filters['kind'] ?? null, static fn ($q, string $kind) => $q->where('kind', $kind))
            ->orderBy('payment_date', $upcoming ? 'asc' : 'desc')
            ->orderBy('id', $upcoming ? 'asc' : 'desc')
            ->cursorPaginate($config->api->pageSize);

        return response()->json([
            'data' => array_map(MarketViews::payout(...), $page->items()),
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
