<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\DataTransferObjects;

use DateTimeImmutable;

/** A file linked from a publisher's page, and the date that file is for. */
final readonly class DownloadLink
{
    public function __construct(
        public string $url,
        public DateTimeImmutable $publishedOn,
    ) {
    }

    /**
     * The same file, under `$prefix` when the link is on `$host` and was written without it — for a
     * site whose pages live under a path (GLEIF's `/en/…`) and link their files without it. A link to
     * another host, or one already under it, is the same link.
     */
    public function underPath(string $host, string $prefix): self
    {
        $prefix = '/' . trim($prefix, '/');
        $parts = parse_url($this->url);

        $linkHost = is_array($parts) ? ($parts['host'] ?? '') : '';

        if ($prefix === '/' || ! is_array($parts) || strcasecmp($linkHost, $host) !== 0) {
            return $this;
        }

        $path = $parts['path'] ?? '/';

        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return $this;
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return new self("https://{$linkHost}{$prefix}{$path}{$query}", $this->publishedOn);
    }
}
