<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\DataTransferObjects\TestConnectionOverrides;
use App\Domain\Settings\Services\TestAiProviderConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/settings/ai/test-connection` — actually calls the configured provider (or, if any
 * of `base_url`/`api_key`/`model` is given in the body, a not-yet-saved draft) with a trivial
 * request and reports success/failure. Every field is optional; an omitted field falls back to
 * the currently saved/resolved value. See `App\Domain\Settings\Services\TestAiProviderConnection`
 * for the exact classification of failure reasons.
 */
final class TestAiConnectionController extends Controller
{
    public function __invoke(Request $request, TestAiProviderConnection $testConnection): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['nullable', 'url', 'max:2048'],
            'api_key' => ['nullable', 'string', 'min:1', 'max:4096'],
            'model' => ['nullable', 'string', 'min:1', 'max:128'],
        ]);

        $hasOverride = $request->filled('base_url') || $request->filled('api_key') || $request->filled('model');

        $overrides = $hasOverride
            ? new TestConnectionOverrides(
                baseUrl: $validated['base_url'] ?? null,
                apiKey: $validated['api_key'] ?? null,
                model: $validated['model'] ?? null,
            )
            : null;

        return response()->json($testConnection($overrides)->toArray());
    }
}
