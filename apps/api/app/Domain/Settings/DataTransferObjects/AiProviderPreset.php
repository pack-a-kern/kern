<?php

declare(strict_types=1);

namespace App\Domain\Settings\DataTransferObjects;

use App\Domain\Settings\Enums\AiDialect;

/**
 * One entry from `config/ai/providers.php`. Choosing a preset in the UI only prefills fields; it
 * never becomes the stored value on its own, so nothing here is authoritative at request time
 * except `dialect`, which decides the wire format actually used to talk to the provider.
 */
final readonly class AiProviderPreset
{
    /**
     * @param  list<string>  $models
     */
    public function __construct(
        public string $key,
        public string $label,
        public AiDialect $dialect,
        public ?string $baseUrl,
        public bool $requiresApiKey,
        public ?string $modelsPath,
        public AiModelListShape $modelsShape,
        public array $models,
        public ?string $docsUrl,
    ) {
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     dialect: string,
     *     base_url: string|null,
     *     requires_api_key: bool,
     *     can_list_models: bool,
     *     models: list<string>,
     *     docs_url: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'dialect' => $this->dialect->value,
            'base_url' => $this->baseUrl,
            'requires_api_key' => $this->requiresApiKey,
            // The client only needs to know whether to offer the "load models" button; the path
            // and response shape are server-side concerns and are deliberately not exposed.
            'can_list_models' => $this->modelsPath !== null,
            'models' => $this->models,
            'docs_url' => $this->docsUrl,
        ];
    }
}
