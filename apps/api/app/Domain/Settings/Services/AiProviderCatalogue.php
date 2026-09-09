<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\DataTransferObjects\AiModelListShape;
use App\Domain\Settings\DataTransferObjects\AiProviderPreset;
use App\Domain\Settings\Enums\AiDialect;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Reads the provider presets from `config/ai_providers.php` and hands them out as typed objects.
 *
 * A malformed or unknown value in that file throws here, at read time, rather than degrading:
 * a preset whose `dialect` is a typo would otherwise silently fall back to the wrong wire format
 * and produce a connection failure the operator cannot explain from the error alone.
 */
final readonly class AiProviderCatalogue
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * @return list<AiProviderPreset>
     */
    public function all(): array
    {
        $presets = $this->config->get('ai_providers.presets');

        if (! is_array($presets)) {
            throw new RuntimeException('ai_providers.presets is missing or is not an array.');
        }

        $catalogue = [];

        foreach ($presets as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new RuntimeException('Each ai_providers preset must be keyed by a string and hold an array.');
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
        $version = $this->config->get('ai_providers.anthropic_version');

        return is_string($version) ? $version : '2023-06-01';
    }

    public function timeoutSeconds(): int
    {
        $timeout = $this->config->get('ai_providers.timeout_seconds');

        return is_int($timeout) && $timeout > 0 ? $timeout : 10;
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
            throw new RuntimeException("ai_providers preset [{$key}] is missing a non-empty [{$field}].");
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
            throw new RuntimeException("ai_providers preset [{$key}] has a non-string [{$field}].");
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
