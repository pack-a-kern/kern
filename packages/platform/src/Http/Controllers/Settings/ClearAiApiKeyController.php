<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Settings\Services\AiProviderSettingsService;

/**
 * `DELETE /api/v1/settings/ai/api-key` — clears the stored API key without touching any other
 * field. A separate endpoint (rather than overloading `PUT .../ai` with a "clear" sentinel value)
 * because the two operations have different, unambiguous intents — mirrors this codebase's
 * existing `POST`/`DELETE .../document-ai-consent` grant/revoke split
 * (`App\Http\Controllers\Api\V1\Documents\DocumentAiConsentController`).
 */
final class ClearAiApiKeyController
{
    public function __invoke(Request $request, AiProviderSettingsService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return response()->json($service->clearApiKey($user->id)->toArray());
    }
}
