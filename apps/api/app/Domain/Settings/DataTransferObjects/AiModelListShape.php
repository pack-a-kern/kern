<?php

declare(strict_types=1);

namespace App\Domain\Settings\DataTransferObjects;

/**
 * How a provider's model-list endpoint shapes its response body. Two shapes cover every provider
 * in `config/ai_providers.php`; an unrecognised value in that file is a configuration error and
 * fails loudly at catalogue-read time rather than producing an empty model list at runtime.
 */
enum AiModelListShape: string
{
    /** `{"data": [{"id": "gpt-4o"}, ...]}` — OpenAI, Anthropic, OpenRouter, most gateways. */
    case DATA_ID = 'data_id';

    /** `{"models": [{"name": "llama3.2:3b"}, ...]}` — Ollama's native `/api/tags`. */
    case MODELS_NAME = 'models_name';

    /**
     * Pull the model identifiers out of a decoded response body, ignoring entries that do not
     * carry a usable string identifier rather than failing the whole fetch over one bad row.
     *
     * @param  array<array-key, mixed>  $body
     * @return list<string>
     */
    public function extract(array $body): array
    {
        [$listKey, $idKey] = match ($this) {
            self::DATA_ID => ['data', 'id'],
            self::MODELS_NAME => ['models', 'name'],
        };

        $rows = $body[$listKey] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        $models = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row[$idKey] ?? null;

            if (is_string($id) && $id !== '') {
                $models[] = $id;
            }
        }

        sort($models);

        return array_values(array_unique($models));
    }
}
