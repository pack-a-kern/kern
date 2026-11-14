<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Settings\Services\BrowserProxySettingsService;

/** `GET /api/v1/settings/browser/proxy` — the proxy as the Browser card shows it, never its password. */
final class ShowBrowserProxyController
{
    public function __invoke(BrowserProxySettingsService $service): JsonResponse
    {
        return response()->json($service->currentView());
    }
}
