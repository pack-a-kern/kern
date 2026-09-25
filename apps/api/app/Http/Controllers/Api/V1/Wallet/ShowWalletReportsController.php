<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Queries\WalletReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * `GET .../wallet/reports` — a year read three ways: by tag, by bank, by category.
 *
 * The period defaults to the current year because that is the question people ask of their own
 * money, and because a report with no default is a screen that opens empty.
 */
final class ShowWalletReportsController
{
    public function __invoke(Request $request, string $subject, WalletReports $reports): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $from = is_string($validated['from'] ?? null)
            ? $validated['from']
            : Carbon::now('UTC')->startOfYear()->toDateString();
        $to = is_string($validated['to'] ?? null)
            ? $validated['to']
            : Carbon::now('UTC')->endOfYear()->toDateString();

        return response()->json([
            ...$reports->between($from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }
}
