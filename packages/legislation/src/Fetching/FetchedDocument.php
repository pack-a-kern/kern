<?php

declare(strict_types=1);

namespace Kern\Legislation\Fetching;

/**
 * The result of one successful, allowlist-verified, size-bounded fetch ({@see PageFetcher::fetch()}). `finalUrl` is the URL actually served content
 * (after following zero or more allowlisted redirects) — kept for provenance even though only the
 * content bytes are persisted.
 */
final readonly class FetchedDocument
{
    public function __construct(
        public string $body,
        public ?string $contentType,
        public string $finalUrl,
        public int $statusCode,
    ) {
    }
}
