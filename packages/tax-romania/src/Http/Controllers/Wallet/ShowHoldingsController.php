<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Routing\PageSizes;
use Kern\TaxRomania\Wallet\Queries\HoldingPositions;

/**
 * `GET /api/v1/subjects/{subject}/wallet/holdings` — the taxpayer's holdings, one position per instrument
 * per account, each with its purchases: those still held (`state=open`) or sold (`state=sold`), a numbered
 * `page` of `per_page` (KERN_PAGE_SIZES) at a time, valued as of today, with how many there are in all.
 */
final class ShowHoldingsController
{
    public function __invoke(Request $request, HoldingPositions $positions, PageSizes $sizes): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'in:open,sold'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => $sizes->rule(),
        ]);
        $page = is_numeric($validated['page'] ?? null) ? max(1, (int) $validated['page']) : 1;
        $perPage = $sizes->of($validated['per_page'] ?? null);

        return response()->json([
            ...$positions->page($validated['state'] === 'open', $page, $perPage),
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }
}
