<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Models\IssuerProfile;

/** `GET /api/v1/investments/issuers/{key}` — a company's profile as last read; 404 when never read. Never reaches out. */
final class ShowIssuerProfileController
{
    public function __invoke(string $key): JsonResponse
    {
        return response()->json(MarketViews::profile(IssuerProfile::query()->where('issuer_key', $key)->firstOrFail()));
    }
}
