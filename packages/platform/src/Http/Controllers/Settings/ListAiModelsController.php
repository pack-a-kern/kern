<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Settings\DataTransferObjects\ModelListResult;
use Kern\Platform\Settings\Services\FetchAiProviderModels;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;

/**
 * `POST /api/v1/settings/ai/models` — asks the saved provider which models it serves.
 *
 * Always the saved address with the key saved alongside it. It used to take a draft address and fill
 * in the stored key when none was typed, which sent the key to whatever address an administrator
 * named — so an address or key in the body is refused, not ignored. Setting up a new provider is
 * saving its address and key first (`ReplaceAiConnectionController`), then listing.
 *
 * `provider` may still be given: it only chooses how the request is shaped — which path, which
 * header — for the provider picked on the form but not yet saved. It never changes where the request
 * goes or which key it carries.
 */
final class ListAiModelsController
{
    public function __invoke(
        Request $request,
        FetchAiProviderModels $fetchModels,
        ResolveAiProviderCredentials $resolveCredentials,
    ): JsonResponse {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:64'],
            'base_url' => ['prohibited'],
            'api_key' => ['prohibited'],
        ]);

        $credentials = $resolveCredentials();
        $provider = $validated['provider'] ?? $credentials->provider;

        if ($provider === null || $credentials->baseUrl === null) {
            return response()->json(ModelListResult::notConfigured()->toArray());
        }

        return response()->json($fetchModels($provider, $credentials->baseUrl, $credentials->apiKey)->toArray());
    }
}
