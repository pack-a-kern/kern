<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Fx;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Fx\Models\FxRate;

/**
 * `GET /v1/fx/rates/history?currency=EUR&months=3` — what a currency has been worth, lately.
 *
 * ## Not scoped to a taxpayer
 *
 * A BNR reference rate is published, identical for everybody, and belongs to no subject — the table it comes
 * from carries the `ext_` prefix the reference data uses for exactly that reason. Putting this behind
 * `{subject}` would imply the figures differ per taxpayer, and a reader comparing two subjects' dashboards
 * would be right to wonder why they do not.
 *
 * ## Lei per unit, with the multiplier applied
 *
 * BNR quotes some currencies per 100 units — HUF, JPY, KRW — and `multiplier` is how that is recorded. A chart
 * plotting `rate_decimal` unadjusted would show the forint at roughly a hundred times its worth, which looks
 * like a currency that moved rather than a column that was not read.
 */
final class ShowFxRateHistoryController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            // Three months to five years. Beyond that the line says less with every point added, and the
            // series is a glance rather than a dataset — there is an export for the dataset.
            'months' => ['required', 'integer', 'in:3,6,12,60'],
        ]);

        $currency = strtoupper($data['currency']);
        $from = now('UTC')->subMonths((int) $data['months'])->toDateString();

        $points = FxRate::query()
            ->where('currency_code', $currency)
            ->where('rate_date', '>=', $from)
            ->orderBy('rate_date')
            ->get(['rate_date', 'rate_decimal', 'multiplier'])
            ->map(static fn (FxRate $rate): array => [
                'date' => $rate->rate_date->toDateString(),
                /*
                 * A string, not a float. These are decimals, and a rate that round-trips through a float can
                 * differ in its last place from the one recorded — which is why the column is not a float
                 * either. The screen parses it once to plot a line and never does arithmetic with it.
                 */
                'rate' => rtrim(rtrim(bcdiv(
                    (string) $rate->rate_decimal,
                    (string) max(1, (int) $rate->multiplier),
                    6,
                ), '0'), '.'),
            ])
            ->all();

        return response()->json(['currency' => $currency, 'points' => $points]);
    }
}
