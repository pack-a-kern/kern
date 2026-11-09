<?php

declare(strict_types=1);

namespace Kern\Investments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Investments\Http\Views\MarketViews;
use Kern\Investments\Services\IssuerProfiles;

/**
 * `POST /api/v1/investments/issuers/{key}/refresh` — a company's profile read from BVB now, only because a
 * person asked; the stored one while it is younger than its refresh age. On its own budget, since it is the
 * one call here that reaches out.
 */
final class RefreshIssuerProfileController
{
    public function __invoke(string $key, IssuerProfiles $profiles): JsonResponse
    {
        return response()->json(MarketViews::profile($profiles->refresh($key)));
    }
}
