<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\Services\FetchAiProviderModels;
use App\Domain\Settings\Services\ResolveAiProviderCredentials;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/settings/ai/models` — asks the provider which models it serves.
 *
 * POST rather than GET because the request body carries a draft `api_key`: an operator listing
 * models for a key they have typed but not yet saved must not have that key end up in a URL, a
 * server access log, or the browser's history (BLUEPRINT.md §4.10).
 *
 * Every field is optional; an omitted one falls back to the currently saved configuration, so the
 * button also works for an already-configured provider whose key is stored and never re-sent to
 * the browser.
 */
final class ListAiModelsController extends Controller
{
    public function __invoke(
        Request $request,
        FetchAiProviderModels $fetchModels,
        ResolveAiProviderCredentials $resolveCredentials,
    ): JsonResponse {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:64'],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'api_key' => ['nullable', 'string', 'min:1', 'max:4096'],
        ]);

        $credentials = $resolveCredentials();

        $provider = $validated['provider'] ?? $credentials->provider;
        $baseUrl = $validated['base_url'] ?? $credentials->baseUrl;
        $apiKey = $validated['api_key'] ?? $credentials->apiKey;

        if ($provider === null || $baseUrl === null) {
            return response()->json(
                ['ok' => false, 'models' => [], 'reason' => 'not_configured'],
            );
        }

        return response()->json($fetchModels($provider, $baseUrl, $apiKey)->toArray());
    }
}
