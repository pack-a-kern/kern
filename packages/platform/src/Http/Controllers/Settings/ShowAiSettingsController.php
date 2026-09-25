<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Settings\Services\AiProviderSettingsService;

/**
 * `GET /api/v1/settings/ai` — the current AI provider configuration. **Never returns the API
 * key** (`docs/handoff/041-settings-module.md`); only `api_key_set`/`api_key_last_four`.
 * Authorization (`permission:system.settings.view`) is enforced entirely by route middleware — see
 * `routes/api.php`.
 */
final class ShowAiSettingsController
{
    public function __invoke(AiProviderSettingsService $service): JsonResponse
    {
        return response()->json($service->currentView()->toArray());
    }
}
