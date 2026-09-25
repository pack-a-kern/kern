<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Settings\Services\AiProviderCatalogue;

/**
 * `GET /api/v1/settings/ai/providers` — the preset catalogue from `config/ai/providers.php`.
 *
 * Served from the API rather than compiled into the frontend bundle so adding a provider is a
 * config edit with no rebuild, per BLUEPRINT.md §19.1. Contains no secrets and no per-installation
 * values: it is the same static list for every caller, describing what *could* be configured, not
 * what is.
 */
final class ShowAiProvidersController
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
