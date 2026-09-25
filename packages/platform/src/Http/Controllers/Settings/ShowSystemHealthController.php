<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Settings\Services\ProbeSystemHealth;

/**
 * `GET /api/v1/settings/health` — live state of the internal services this installation runs.
 *
 * Requires `system.settings.view`, like every route in this group: the component list and the
 * failure detail describe internal topology, which is not something an ordinary authenticated user
 * needs. Always answers 200 — a degraded component is the payload, not an error, or the screen
 * could not distinguish "everything is down" from "the status check is down".
 */
final class ShowSystemHealthController
{
    public function __invoke(ProbeSystemHealth $probe): JsonResponse
    {
        return response()->json([
            'components' => array_map(
                static fn ($component): array => $component->toArray(),
                $probe(),
            ),
        ]);
    }
}
