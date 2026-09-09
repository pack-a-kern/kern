<?php

declare(strict_types=1);

namespace App\Domain\Settings\DataTransferObjects;

/**
 * What `PUT /api/v1/settings/ai` accepts, after HTTP-layer validation
 * (`App\Http\Controllers\Api\V1\Settings\UpdateAiSettingsController`).
 *
 * `$apiKey` is the one field that is genuinely optional (`null` means "leave the stored key
 * unchanged") — the API never returns the current key (`AiProviderPublicView`), so the caller has
 * no way to resend it even if it wanted to keep it unchanged by round-tripping. Every other field
 * is a full replacement: they are always rendered back to the caller in `AiProviderPublicView`,
 * so there is no "leave unchanged" ambiguity to support for them.
 */
final readonly class UpdateAiProviderSettingsInput
{
    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $baseUrl,
        public string $model,
        public ?string $apiKey,
    ) {
    }
}
