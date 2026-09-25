<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\DataTransferObjects\AiModelListShape;
use Kern\Platform\Settings\DataTransferObjects\AiProviderPreset;
use Kern\Platform\Settings\Enums\AiDialect;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use RuntimeException;

/**
 * Reads the provider presets from `config/ai/providers.php` and hands them out as typed objects.
 *
 * A malformed or unknown value in that file throws here, at read time, rather than degrading:
 * a preset whose `dialect` is a typo would otherwise silently fall back to the wrong wire format
 * and produce a connection failure the operator cannot explain from the error alone.
 */
final readonly class AiProviderCatalogue
{
    public function __construct(
        private ConfigRepository $config,
        private SettingsRepository $settings,
    ) {
    }

    /**
     * @return list<AiProviderPreset>
     */
    public function all(): array
    {
        $presets = $this->config->get('ai.providers.presets');

        if (! is_array($presets)) {
            throw new RuntimeException('ai.providers.presets is missing or is not an array.');
        }

        $catalogue = [];

        foreach ($presets as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new RuntimeException('Each ai.providers preset must be keyed by a string and hold an array.');
            }

            $catalogue[] = $this->hydrate($key, $definition);
        }

        return $catalogue;
    }

    /**
     * The preset a stored `ai.provider` value refers to, or null when it names none — which is
     * the normal state for an operator who configured AI through env before presets existed.
     */
    public function find(?string $key): ?AiProviderPreset
    {
        if ($key === null) {
            return null;
        }

        foreach ($this->all() as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * The wire format to use for a provider key. An unrecognised key — a hand-written value, or a
     * preset removed from config after it was saved — falls back to the OpenAI-compatible shape,
     * which is what `docs/adr/0007-provider-agnostic-ai.md` treats as the default protocol.
     */
    public function dialectFor(?string $providerKey): AiDialect
    {
        $preset = $this->find($providerKey);

        return $preset instanceof AiProviderPreset ? $preset->dialect : AiDialect::OPENAI;
    }

    /**
     * Anthropic requires a pinned API version on every request. Read here rather than in each
     * caller so the two services that talk to providers share one source for it.
     */
    public function anthropicVersion(): string
    {
        $version = $this->config->get('ai.providers.anthropic_version');

        return is_string($version) ? $version : '2023-06-01';
    }

    public function timeoutSeconds(): int
    {
        return $this->config->integer('ai.providers.timeout_seconds');
    }

    /**
     * How long one document extraction may take.
     *
     * Separate from {@see self::timeoutSeconds()} on purpose. That one bounds a *ping* — one token,
     * answered in milliseconds — and 10 seconds is a sane ceiling for deciding a provider is dead.
     * Reading a photograph on a self-hosted vision model is a different order of work: loading the
     * model, then the image, then generating. Reusing the ping's ceiling would abandon every real
     * extraction and report the provider as unreachable, which is exactly the wrong diagnosis.
     *
     * Runs on a queue worker, never in a request, so a long wait costs nobody a loading spinner.
     */
    public function extractionTimeoutSeconds(): int
    {
        $stored = SettingType::decodeInteger($this->settings->get(SettingKey::AI_EXTRACTION_TIMEOUT_SECONDS));

        if ($stored !== null && $stored > 0) {
            return $stored;
        }

        return $this->config->integer('ai.documents.timeout_seconds');
    }

    /** Time a queued job needs around the model call itself — see `ai.providers.job_timeout_margin_seconds`. */
    public function jobTimeoutMarginSeconds(): int
    {
        return $this->config->integer('ai.providers.job_timeout_margin_seconds');
    }

    /** Whether the configured endpoint can clean up a photographed document before reading it. */
    public function supportsEnhancement(): bool
    {
        $stored = SettingType::decodeBoolean($this->settings->get(SettingKey::AI_SUPPORTS_ENHANCEMENT));

        return $stored ?? (bool) $this->config->get('ai.providers.supports_enhancement', false);
    }

    /** Whether the configured endpoint can turn a PDF statement into CSV before it is read. */
    public function supportsConversion(): bool
    {
        $stored = SettingType::decodeBoolean($this->settings->get(SettingKey::AI_SUPPORTS_CONVERSION));

        return $stored ?? (bool) $this->config->get('ai.providers.supports_conversion', false);
    }

    /**
     * How many pages a document may have before it is refused unread.
     *
     * The gate that makes everything downstream affordable: a provider reading a 260-page statement
     * costs minutes, and counting the pages to refuse it costs milliseconds. Editable because what
     * is reasonable depends entirely on the model and the patience behind it.
     */
    public function maxDocumentPages(): int
    {
        $stored = SettingType::decodeInteger($this->settings->get(SettingKey::DOCUMENTS_MAX_PAGES));

        if ($stored !== null && $stored > 0) {
            return $stored;
        }

        $max = $this->config->get('documents.max_pages');

        return is_int($max) && $max > 0 ? $max : 1;
    }

    /**
     * The largest document that may be sent for a model to look at.
     *
     * Below the 15 MB an upload is allowed to be, deliberately. Base64 adds a third on top, a
     * gateway's own body limit is usually smaller than either, and the browser already downscales
     * a photograph to 2200px before uploading — so a file arriving above this is one that came in
     * by some other path and is almost certainly larger than it needs to be.
     *
     * A document over the ceiling is not truncated. Half a receipt is not a smaller receipt.
     */
    public function extractionMaxImageBytes(): int
    {
        $max = $this->config->get('ai.documents.max_image_bytes');

        return is_int($max) && $max > 0 ? $max : 8 * 1024 * 1024;
    }

    /**
     * A ceiling on the reply, not the document.
     *
     * Bounds a model that ignores the instruction and starts narrating; without it, one runs until
     * the timeout, costing money on a metered provider and a worker slot on a self-hosted one.
     *
     * Generous because a *reasoning* model spends the same budget thinking before it answers. A
     * tighter cap does not truncate the JSON — it consumes the whole allowance on the chain of
     * thought and returns empty `content`, which then fails schema validation and looks exactly
     * like a model that could not read the document.
     */
    /**
     * How long the model's reply may be.
     *
     * Read through `SettingsRepository`, so an administrator changing it on the settings screen
     * takes effect on the very next document — the same per-call resolution the provider and model
     * already use. The stored value falls back to `config/ai/documents.php`, which is the only
     * place the number itself is written down.
     */
    public function extractionMaxTokens(): int
    {
        $stored = SettingType::decodeInteger($this->settings->get(SettingKey::AI_EXTRACTION_MAX_TOKENS));

        if ($stored !== null && $stored > 0) {
            return $stored;
        }

        $max = $this->config->get('ai.documents.max_tokens');

        // A blanked-out or non-numeric config value must not make extraction impossible, so the
        // floor is the smallest reply that could hold a reading rather than a second copy of the
        // default — which would be the duplicate this method exists to avoid.
        return is_int($max) && $max > 0 ? $max : 1;
    }

    /**
     * @param  array<array-key, mixed>  $definition
     */
    private function hydrate(string $key, array $definition): AiProviderPreset
    {
        $modelsPath = $definition['models_path'] ?? null;

        return new AiProviderPreset(
            key: $key,
            label: $this->requireString($key, $definition, 'label'),
            dialect: AiDialect::from($this->requireString($key, $definition, 'dialect')),
            baseUrl: $this->optionalString($key, $definition, 'base_url'),
            requiresApiKey: (bool) ($definition['requires_api_key'] ?? false),
            modelsPath: is_string($modelsPath) && $modelsPath !== '' ? $modelsPath : null,
            modelsShape: AiModelListShape::from($this->requireString($key, $definition, 'models_shape')),
            models: $this->stringList($definition['models'] ?? []),
            docsUrl: $this->optionalString($key, $definition, 'docs_url'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $definition
     */
    private function requireString(string $key, array $definition, string $field): string
    {
        $value = $definition[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("ai.providers preset [{$key}] is missing a non-empty [{$field}].");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $definition
     */
    private function optionalString(string $key, array $definition, string $field): ?string
    {
        $value = $definition[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException("ai.providers preset [{$key}] has a non-string [{$field}].");
        }

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
