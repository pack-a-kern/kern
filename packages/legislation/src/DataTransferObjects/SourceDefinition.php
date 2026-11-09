<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

use Kern\Legislation\Enums\TrustTier;

/**
 * One `config/sources.php` entry, resolved and typed. This is the only shape
 * `Kern\Legislation\Fetching\AllowlistedHttpFetcher` trusts for a host allowlist or a
 * source's User-Agent (decided by who it goes out as) — never a raw config array passed around by callers.
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
        /** This source's own ceiling on one request, where the shared one is too short for it. */
        public ?int $timeoutSeconds = null,
        /** How its pages are read: downloaded (`direct`), or opened by the browser worker (`browser`). */
        public string $pages = self::PAGES_DIRECT,
        /** The config key of the playbook the browser worker is sent for this source, if it has one. */
        public ?string $playbook = null,
    ) {
    }

    public const string PAGES_DIRECT = 'direct';

    public const string PAGES_BROWSER = 'browser';

    public function allowsHost(string $host): bool
    {
        return in_array(strtolower($host), $this->allowedHosts, true);
    }

    /**
     * Whether `$url` may be fetched for this source: https, and a host the allowlist names.
     *
     * One rule for every way a page is read — downloaded, or opened in a browser — so neither can be
     * pointed somewhere the other would refuse.
     */
    public function allowsUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https' && is_string($host) && $host !== '' && $this->allowsHost($host);
    }
}
