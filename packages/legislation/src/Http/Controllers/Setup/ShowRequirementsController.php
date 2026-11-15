<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Setup;

use Illuminate\Http\JsonResponse;
use Kern\Legislation\Requirements\ReadinessReport;

/**
 * `GET /api/v1/setup/requirements` — what every package declared it needs to operate, each need
 * resolved to met or not, with `is_bootstrapped` (every `required` requirement met) and, per feature,
 * `available` (ADR 0027).
 *
 * Install state, not any one taxpayer's data: outside the tenant group, like the rest of this
 * package's routes. The screen reads this and renders the checklist — it computes nothing itself
 * (`docs/UI-PATTERNS.md`).
 */
final class ShowRequirementsController
{
    public function __invoke(ReadinessReport $readiness): JsonResponse
    {
        return response()->json($readiness());
    }
}
