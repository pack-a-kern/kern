<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\Services\AiProviderSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/settings/ai` — the current AI provider configuration. **Never returns the API
 * key** (`docs/handoff/041-settings-module.md`); only `api_key_set`/`api_key_last_four`.
 * Authorization (`permission:system.settings.view`) is enforced entirely by route middleware — see
 * `routes/api.php`.
 */
final class ShowAiSettingsController extends Controller
{
    public function __invoke(AiProviderSettingsService $service): JsonResponse
    {
        return response()->json($service->currentView()->toArray());
    }
}
