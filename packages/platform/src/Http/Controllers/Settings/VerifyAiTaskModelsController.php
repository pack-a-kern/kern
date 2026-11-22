<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Settings\Services\AiTaskModels;
use Kern\Platform\Settings\Services\FetchAiProviderModels;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;

/**
 * `POST /api/v1/settings/ai/task-models/verify` — asks the saved provider whether each task's model
 * actually exists on it.
 *
 * One call to the provider's model list ({@see FetchAiProviderModels}), then each distinct task
 * model is looked up in it — so an operator who typed `qwen3:8b` where the runtime only has
 * `qwen3:4b` is told before the first import fails on it. Reaches the provider with real
 * credentials, so it is a write in the sense that matters (the manage permission, throttled), like
 * the connection test beside it, though it stores nothing.
 *
 * `checkable` is false, with a reason, whenever the list could not be had — nothing configured, a
 * provider that publishes no list, an unreachable address — because "cannot tell" is an ordinary
 * outcome the screen shows plainly rather than a red cross that would wrongly read as "missing".
 */
final class VerifyAiTaskModelsController
{
    public function __invoke(
        AiTaskModels $taskModels,
        FetchAiProviderModels $fetchModels,
        ResolveAiProviderCredentials $resolveCredentials,
    ): JsonResponse {
        $credentials = $resolveCredentials();

        if ($credentials->provider === null || $credentials->baseUrl === null) {
            return response()->json(['checkable' => false, 'reason' => 'not_configured', 'exists' => (object) []]);
        }

        $result = $fetchModels($credentials->provider, $credentials->baseUrl, $credentials->apiKey, 'verify-models');

        if (! $result->ok) {
            return response()->json(['checkable' => false, 'reason' => $result->reason, 'exists' => (object) []]);
        }

        $served = array_fill_keys($result->models, true);
        $exists = [];

        foreach ($taskModels->distinctModels() as $model) {
            $exists[$model] = isset($served[$model]);
        }

        return response()->json(['checkable' => true, 'reason' => null, 'exists' => (object) $exists]);
    }
}
