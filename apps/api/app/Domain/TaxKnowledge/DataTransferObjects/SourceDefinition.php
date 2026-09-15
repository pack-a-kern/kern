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
        /** Which source is preferred when two publish the same data: 1 before 2. */
        public int $priority,
        /**
         * The TLS key-exchange groups to offer, colon-separated as OpenSSL writes them, or null for the
         * library's default. For a server whose firewall refuses the default handshake.
         */
        public ?string $tlsCurves = null,
    ) {
    }

    public function allowsHost(string $host): bool
    {
        return in_array(strtolower($host), $this->allowedHosts, true);
    }
}
