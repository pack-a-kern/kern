<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Wallet\Services\DepositTerm;
use LogicException;

/**
 * `GET /api/v1/subjects/{subject}/wallet/deposits/term?starts_at=&term_months=` — what a term makes of a
 * start, for the deposit form to show while it is filled in: the maturity, whether a weekend moved it to
 * the Monday after, and the days interest runs over. Worked out by the same rules a saved deposit is
 * ({@see DepositTerm}); reads nothing and stores nothing.
 */
final class ShowDepositTermController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'term_months' => ['required', 'integer', 'min:1', 'max:' . DepositFields::longestTerm()],
        ]);

        $startsAt = CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['starts_at'], 'UTC')?->startOfDay()
            ?? throw new LogicException('A validated date did not parse.');
        $months = (int) $validated['term_months'];
        $maturity = DepositTerm::maturity($startsAt, $months);

        return response()->json([
            'maturityAt' => $maturity->toDateString(),
            'movedFromWeekend' => ! $maturity->isSameDay(DepositTerm::end($startsAt, $months)),
            'days' => DepositTerm::days($startsAt, $maturity),
        ]);
    }
}
