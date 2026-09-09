<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Settings\DataTransferObjects\UpdateAiProviderSettingsInput;
use App\Domain\Settings\Services\AiProviderSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/settings/ai` — replaces the AI provider configuration. `enabled`/`provider`/
 * `base_url`/`model` are a full replacement (always sent); `api_key` is the one field that may be
 * omitted to mean "leave the stored key unchanged" (`docs/handoff/041-settings-module.md` — the
 * API never returns the current key, so the caller cannot round-trip it).
 *
 * Response is the same redacted shape `GET /api/v1/settings/ai` returns — never the key itself.
 */
final class UpdateAiSettingsController extends Controller
{
    public function __invoke(Request $request, AiProviderSettingsService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', 'string', 'min:1', 'max:64'],
            'base_url' => ['required', 'url', 'max:2048'],
            'model' => ['required', 'string', 'min:1', 'max:128'],
            'api_key' => ['nullable', 'string', 'min:1', 'max:4096'],
        ]);

        $input = new UpdateAiProviderSettingsInput(
            enabled: (bool) $validated['enabled'],
            provider: (string) $validated['provider'],
            baseUrl: (string) $validated['base_url'],
            model: (string) $validated['model'],
            apiKey: $request->filled('api_key') ? (string) $validated['api_key'] : null,
        );

        return response()->json($service->update($input, $user->id)->toArray());
    }
}
