<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Settings\Rules\AiProviderAddress;
use Kern\Platform\Settings\Services\AiProviderSettingsService;

/**
 * `PUT /api/v1/settings/ai/connection` — the provider's address and its key, set together. The
 * settings screen's connection dialog is the only caller, and this is the only way either changes:
 * the general settings update refuses both (`SettingKey::isSetOnlyWithItsPair()`).
 *
 * Both are required in the sense that both are decided here: an empty or missing `api_key` means the
 * provider takes no key, never "keep the one saved". The address is held to {@see AiProviderAddress}.
 *
 * The response is the redacted view — never the key.
 */
final class ReplaceAiConnectionController
{
    public function __invoke(Request $request, AiProviderSettingsService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'base_url' => ['required', 'string', new AiProviderAddress()],
            'api_key' => ['nullable', 'string', 'max:4096'],
        ]);

        $apiKey = is_string($validated['api_key'] ?? null) && $validated['api_key'] !== '' ? $validated['api_key'] : null;

        return response()->json(
            $service->replaceConnection((string) $validated['base_url'], $apiKey, (string) $user->id)->toArray(),
        );
    }
}
