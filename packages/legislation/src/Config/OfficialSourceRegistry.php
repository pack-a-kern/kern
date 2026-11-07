<?php

declare(strict_types=1);

namespace Kern\Legislation\Config;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\DataTransferObjects\SourceDefinition;
use Kern\Legislation\Enums\TrustTier;
use Kern\Legislation\Exceptions\UnknownSourceException;
use Kern\Platform\Settings\Enums\SettingType;
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
     * The crawler as it names itself to a source with no name of its own to be called by:
     * "Kern-RO-SourceCrawler/1.0 (contact: ops@example.ro)", or the name alone.
     */
    private function crawlerUserAgent(): string
    {
        $name = $this->config->get('sources.crawler.name');
        $contact = $this->config->get('sources.crawler.contact');

        if (! is_string($name) || ! SettingType::isHeaderValue($name)) {
            throw new RuntimeException('config/sources.php must name the crawler (crawler.name) in text a header can carry.');
        }

        $contact = is_string($contact) ? trim($contact) : '';

        if ($contact !== '' && ! SettingType::isContact($contact)) {
            throw new RuntimeException('config/sources.php has a crawler.contact that is not an e-mail address or an https address.');
        }

        $userAgent = trim($name) . ($contact === '' ? '' : " (contact: {$contact})");

        // Each half fits; together they may not.
        if (! SettingType::isHeaderValue($userAgent)) {
            throw new RuntimeException('The crawler name and contact together are too long for a User-Agent.');
        }

        return $userAgent;
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
        $pages = $entry['pages'] ?? SourceDefinition::PAGES_DIRECT;
        $playbook = $entry['playbook'] ?? null;

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

        // The same rule the settings screen holds a typed value to, for a value from `.env`: printable
        // ASCII on one line. A line break in a header is a second header nobody meant to send.
        if ($userAgent !== null && (! is_string($userAgent) || ! SettingType::isHeaderValue($userAgent))) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" has a user_agent that cannot be sent as a header.', $code));
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

        if ($pages !== SourceDefinition::PAGES_DIRECT && $pages !== SourceDefinition::PAGES_BROWSER) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" reads its pages "direct" or "browser", not %s.', $code, json_encode($pages)));
        }

        if ($playbook !== null && (! is_string($playbook) || $playbook === '')) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" has a playbook that is not a config key.', $code));
        }

        // Refused here rather than at the first page: a source set to the browser with nothing to tell
        // the browser is a configuration that cannot work, and it should not wait for a law to say so.
        if ($pages === SourceDefinition::PAGES_BROWSER && $playbook === null) {
            throw new RuntimeException(sprintf('config/sources.php entry "%s" reads its pages in a browser but names no playbook.', $code));
        }

        return new SourceDefinition(
            code: $code,
            trustTier: TrustTier::from($trustTier),
            baseUrl: $baseUrl,
            allowedHosts: $normalizedHosts,
            userAgent: $userAgent ?? $this->crawlerUserAgent(),
            enabled: $enabled,
            priority: $priority,
            tlsCurves: $tlsCurves,
            timeoutSeconds: $timeoutSeconds,
            pages: $pages,
            playbook: $playbook,
        );
    }
}
