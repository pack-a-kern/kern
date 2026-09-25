<?php

declare(strict_types=1);

namespace Kern\Legislation\Config;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\DataTransferObjects\SourceDefinition;
use Kern\Legislation\Enums\TrustTier;
use Kern\Legislation\Exceptions\UnknownSourceException;
use RuntimeException;

/**
 * Reads `config/sources.php` — the versioned, code-reviewed source registry (`BLUEPRINT.md` §8.2).
 * Pure with respect to the rest of the application (no HTTP, no database): given a config
 * repository, it either returns a fully-typed {@see SourceDefinition} or throws. Every other
 * class in this module and in `Domain/Fx` gets its notion of "which hosts are allowed for source
 * X" exclusively through this class — never by reading `config()` directly a second way.
 */
final readonly class OfficialSourceRegistry
{
    public function __construct(
        private ConfigRepository $config,
    ) {
    }

    public function find(string $code): SourceDefinition
    {
        $entry = $this->config->get("sources.entries.{$code}");

        if (! is_array($entry)) {
            throw UnknownSourceException::forCode($code);
        }

        return $this->definitionFromArray($code, $entry);
    }

    /**
     * @return list<SourceDefinition>
     */
    public function all(): array
    {
        $entries = $this->config->get('sources.entries', []);

        if (! is_array($entries)) {
            throw new RuntimeException('config/sources.php "entries" must be an array.');
        }

        $definitions = [];

        foreach ($entries as $code => $entry) {
            if (! is_string($code) || ! is_array($entry)) {
                throw new RuntimeException('config/sources.php "entries" must be keyed by source code, each an array.');
            }

            $definitions[] = $this->definitionFromArray($code, $entry);
        }

        return $definitions;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function definitionFromArray(string $code, array $entry): SourceDefinition
    {
        $trustTier = $entry['trust_tier'] ?? null;
        $baseUrl = $entry['base_url'] ?? null;
        $allowedHosts = $entry['allowed_hosts'] ?? null;
        $userAgent = $entry['user_agent'] ?? null;
        $enabled = $entry['enabled'] ?? null;
        $priority = $entry['priority'] ?? null;
        $tlsCurves = $entry['tls_curves'] ?? null;
        $timeoutSeconds = $entry['timeout_seconds'] ?? null;

        if (! is_string($trustTier) || TrustTier::tryFrom($trustTier) === null) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" has an invalid or missing trust_tier.', $code));
        }

        if ($baseUrl !== null && ! is_string($baseUrl)) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" has a non-string base_url.', $code));
        }

        if (! is_array($allowedHosts) || $allowedHosts === []) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" must declare a non-empty allowed_hosts list.', $code));
        }

        $normalizedHosts = [];

        foreach ($allowedHosts as $host) {
            if (! is_string($host) || $host === '') {
                throw new RuntimeException(sprintf('config/sources.php entry "%s" has a non-string allowed_hosts entry.', $code));
            }

            $normalizedHosts[] = strtolower($host);
        }

        if (! is_string($userAgent) || $userAgent === '') {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" must declare a non-empty user_agent.', $code));
        }

        if (! is_bool($enabled)) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" must declare a boolean enabled flag.', $code));
        }

        if (! is_int($priority) || $priority < 1) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" must declare a priority of 1 or more.', $code));
        }

        if ($tlsCurves !== null && (! is_string($tlsCurves) || $tlsCurves === '')) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" has a tls_curves that is not a non-empty string.', $code));
        }

        if ($timeoutSeconds !== null && (! is_int($timeoutSeconds) || $timeoutSeconds < 1)) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" has a timeout_seconds that is not a positive integer.', $code));
        }

        return new SourceDefinition(
            code: $code,
            trustTier: TrustTier::from($trustTier),
            baseUrl: $baseUrl,
            allowedHosts: $normalizedHosts,
            userAgent: $userAgent,
            enabled: $enabled,
            priority: $priority,
            tlsCurves: $tlsCurves,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
