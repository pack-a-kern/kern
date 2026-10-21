<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Kern\Platform\Settings\DataTransferObjects\AiProviderCredentials;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * The single place that decides "what AI provider configuration is in effect right now" —
 * consulted fresh on every call, never cached beyond one resolution, so a setting saved on the
 * settings screen takes effect on the very next call with no restart
 * (`docs/handoff/041-settings-module.md`).
 *
 * From the database only, with `config('ai.providers.defaults')` — off, nothing set — for a field
 * never saved. There used to be a per-field fallback to `AI_*` environment variables, which let an
 * address from one source travel with a key from the other; see that config block.
 *
 * Used both by `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction` (the document
 * pipeline) and, redacted, by `Kern\Platform\Settings\Services\AiProviderSettingsService` (the
 * settings screen) — so there is one answer to the question, not two.
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
            ?? (bool) config('ai.providers.defaults.enabled', false);

        $savedBaseUrl = $this->settings->get(SettingKey::AI_BASE_URL);

        return new AiProviderCredentials(
            enabled: $enabled,
            provider: $this->settings->get(SettingKey::AI_PROVIDER) ?? $this->stringDefault('provider'),
            baseUrl: $savedBaseUrl ?? $this->stringDefault('base_url'),
            model: $this->settings->get(SettingKey::AI_MODEL) ?? $this->stringDefault('model'),
            // The key comes from wherever the address came from, and from nowhere else: a saved
            // address with no saved key has no key, never the default one. Pairing them per field
            // is how a key used to reach an address it was never saved with.
            apiKey: $savedBaseUrl !== null
                ? $this->settings->get(SettingKey::AI_API_KEY)
                : $this->stringDefault('api_key'),
        );
    }

    private function stringDefault(string $field): ?string
    {
        $value = config("ai.providers.defaults.{$field}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
