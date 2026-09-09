<?php

declare(strict_types=1);

namespace App\Domain\Settings\DataTransferObjects;

/**
 * Optional per-call overrides for `POST /api/v1/settings/ai/test-connection`, letting an
 * administrator test a draft value (e.g. a new API key being pasted in) *before* saving it —
 * nothing here is persisted. Any field left `null` falls back to the currently resolved/saved
 * credential (`App\Domain\Settings\Services\ResolveAiProviderCredentials`).
 */
final readonly class TestConnectionOverrides
{
    public function __construct(
        public ?string $baseUrl,
        public ?string $apiKey,
        public ?string $model,
    ) {
    }
}
