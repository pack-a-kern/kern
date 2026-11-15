<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Setup;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
 *
 * `poll` carries the one behavioural cadence the screen needs — how often to re-ask this endpoint
 * while an import runs, when to stop spinning, and how long to linger on "complete" before leaving —
 * from `legislation.setup`, so no interval is a number written into the frontend.
 */
final class ShowRequirementsController
{
    public function __invoke(ReadinessReport $readiness, ConfigRepository $config): JsonResponse
    {
        return response()->json([
            ...$readiness(),
            'poll' => [
                'every_ms' => $config->integer('legislation.setup.poll_ms'),
                'ceiling_ms' => $config->integer('legislation.setup.poll_ceiling_ms'),
                'redirect_ms' => $config->integer('legislation.setup.ready_redirect_ms'),
            ],
        ]);
    }
}
