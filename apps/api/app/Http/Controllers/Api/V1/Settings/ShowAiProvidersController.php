<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\Services\AiProviderCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/settings/ai/providers` — the preset catalogue from `config/ai_providers.php`.
 *
 * Served from the API rather than compiled into the frontend bundle so adding a provider is a
 * config edit with no rebuild, per BLUEPRINT.md §19.1. Contains no secrets and no per-installation
 * values: it is the same static list for every caller, describing what *could* be configured, not
 * what is.
 */
final class ShowAiProvidersController extends Controller
{
    public function __invoke(AiProviderCatalogue $catalogue): JsonResponse
    {
        return response()->json([
            'providers' => array_map(
                static fn ($preset): array => $preset->toArray(),
                $catalogue->all(),
            ),
        ]);
    }
}
