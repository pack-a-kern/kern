<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\DataTransferObjects;

use App\Domain\TaxKnowledge\Enums\TrustTier;

/**
 * One `config/sources.php` entry, resolved and typed. This is the only shape
 * `App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher` trusts for a host allowlist or a
 * per-source User-Agent — never a raw config array passed around by callers.
 *
 * @property-read list<string> $allowedHosts already lower-cased, for case-insensitive comparison.
 */
final readonly class SourceDefinition
{
    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(
        public string $code,
        public TrustTier $trustTier,
        public ?string $baseUrl,
        public array $allowedHosts,
        public string $userAgent,
        public bool $enabled,
    ) {
    }

    public function allowsHost(string $host): bool
    {
        return in_array(strtolower($host), $this->allowedHosts, true);
    }
}
