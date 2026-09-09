<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\Services\ProbeSystemHealth;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/settings/health` — live state of the internal services this installation runs.
 *
 * Requires `system.settings.view`, like every route in this group: the component list and the
 * failure detail describe internal topology, which is not something an ordinary authenticated user
 * needs. Always answers 200 — a degraded component is the payload, not an error, or the screen
 * could not distinguish "everything is down" from "the status check is down".
 */
final class ShowSystemHealthController extends Controller
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
