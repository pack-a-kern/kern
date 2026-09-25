<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * The API-safe view of the AI provider settings — the exact shape `GET`/`PUT
 * /api/v1/settings/ai` return. **Never carries the API key itself**, only whether one is set and
 * its last four characters, per the task's explicit requirement that a settings screen must never
 * be able to display or recover a secret it stores.
 */
final readonly class AiProviderPublicView
{
    public function __construct(
        public bool $enabled,
        public ?string $provider,
        public ?string $baseUrl,
        public ?string $model,
        public bool $apiKeySet,
        public ?string $apiKeyLastFour,
        public ?CarbonImmutable $updatedAt,
        public ?string $updatedBy,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'base_url' => $this->baseUrl,
            'model' => $this->model,
            'api_key_set' => $this->apiKeySet,
            'api_key_last_four' => $this->apiKeyLastFour,
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'updated_by' => $this->updatedBy,
        ];
    }
}
