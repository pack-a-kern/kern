<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Settings\Services\TestAiProviderConnection;

/**
 * `POST /api/v1/settings/ai/test-connection` — calls the saved provider with a trivial request and
 * reports whether it connected.
 *
 * Refuses an address, key or model in the body rather than ignoring one: it once accepted a draft
 * address and filled in the stored key, and a request that still sends one is relying on that.
 * Testing a new address is saving it first, with its key (`ReplaceAiConnectionController`).
 */
final class TestAiConnectionController
{
    public function __invoke(Request $request, TestAiProviderConnection $testConnection): JsonResponse
    {
        $request->validate([
            'base_url' => ['prohibited'],
            'api_key' => ['prohibited'],
            'model' => ['prohibited'],
        ]);

        return response()->json($testConnection()->toArray());
    }
}
