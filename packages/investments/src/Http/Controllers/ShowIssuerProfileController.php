<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\IssuerProfile;

/**
 * `GET /api/v1/investments/issuers/{key}` — a listed instrument's profile as last read; null when never
 * read, 404 for a symbol BVB does not list here. Never reaches out.
 */
final class ShowIssuerProfileController
{
    public function __invoke(string $key): JsonResponse
    {
        $instrument = FinancialInstrument::query()->listedOnBvb($key)->firstOrFail();
        $profile = IssuerProfile::query()->where('issuer_key', $key)->first();

        // `json(null)` would answer `{}`: never read is said as JSON's own null.
        return $profile === null ? JsonResponse::fromJsonString('null') : response()->json(MarketViews::profile($profile, $instrument->rate));
    }
}
