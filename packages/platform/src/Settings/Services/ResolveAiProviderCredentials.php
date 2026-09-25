<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Kern\Platform\Settings\DataTransferObjects\AiProviderCredentials;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * The single place that decides "what AI provider configuration is in effect right now" —
 * consulted fresh on every call, never cached beyond one resolution, so a setting saved through
 * `PUT /api/v1/settings/ai` takes effect on the very next call with no restart
 * (`docs/handoff/041-settings-module.md`).
 *
 * Per-field fallback, not all-or-nothing: each of `enabled`/`provider`/`base_url`/`model`/
 * `api_key` falls back independently to `config('ai.providers.env_fallback.*')` only when that
 * specific `settings` row does not exist yet — an existing deployment relying on `AI_*` env vars
 * keeps working unchanged until an administrator explicitly overrides one field through the API.
 *
 * Used both by `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction` (the document
 * pipeline) and, redacted, by `Kern\Platform\Settings\Services\AiProviderSettingsService` (the
 * settings screen) — the fallback logic lives in exactly one place.
 */
final readonly class ResolveAiProviderCredentials
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    public function __invoke(): AiProviderCredentials
    {
        // Decoded through the type that owns the format, not by comparing to a literal here — the
        // literal was `'true'` while the update endpoint stored `'1'`, so this switch read as off
        // however it was set.
        $enabled = SettingType::decodeBoolean($this->settings->get(SettingKey::AI_ENABLED))
            ?? (bool) config('ai.providers.env_fallback.enabled', false);

        return new AiProviderCredentials(
            enabled: $enabled,
            provider: $this->settings->get(SettingKey::AI_PROVIDER) ?? $this->stringFallback('provider'),
            baseUrl: $this->settings->get(SettingKey::AI_BASE_URL) ?? $this->stringFallback('base_url'),
            model: $this->settings->get(SettingKey::AI_MODEL) ?? $this->stringFallback('model'),
            apiKey: $this->settings->get(SettingKey::AI_API_KEY) ?? $this->stringFallback('api_key'),
        );
    }

    private function stringFallback(string $field): ?string
    {
        $value = config("ai.providers.env_fallback.{$field}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
