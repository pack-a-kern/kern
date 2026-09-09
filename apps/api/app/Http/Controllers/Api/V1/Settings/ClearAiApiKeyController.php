<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\Services\AiProviderSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE /api/v1/settings/ai/api-key` — clears the stored API key without touching any other
 * field. A separate endpoint (rather than overloading `PUT .../ai` with a "clear" sentinel value)
 * because the two operations have different, unambiguous intents — mirrors this codebase's
 * existing `POST`/`DELETE .../document-ai-consent` grant/revoke split
 * (`App\Http\Controllers\Api\V1\Documents\DocumentAiConsentController`).
 */
final class ClearAiApiKeyController extends Controller
{
    public function __invoke(Request $request, AiProviderSettingsService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return response()->json($service->clearApiKey($user->id)->toArray());
    }
}
