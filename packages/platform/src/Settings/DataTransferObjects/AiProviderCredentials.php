<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\DataTransferObjects;

/**
 * The resolved AI provider configuration another module needs to actually reach the provider —
 * carries the plaintext API key. **Never log this object, never serialize it into an HTTP
 * response, never pass it to `AuditChainService::hashPayload()` directly** (`BLUEPRINT.md` §4.10).
 * `Kern\Platform\Settings\DataTransferObjects\AiProviderPublicView` is the redacted shape safe to
 * expose over the API; this one is for server-side use only
 * (`Kern\Platform\Settings\Services\ResolveAiProviderCredentials`,
 * `App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction`).
 */
final readonly class AiProviderCredentials
{
    public function __construct(
        public bool $enabled,
        public ?string $provider,
        public ?string $baseUrl,
        public ?string $model,
        public ?string $apiKey,
    ) {
    }
}
