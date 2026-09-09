<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Fetching\Exceptions;

use RuntimeException;

/**
 * Thrown when a URL — the one requested, or a redirect target — is not `https://` or its host is
 * not on the source's `config/sources.php` allowlist. This is the SSRF control from `BLUEPRINT.md`
 * §4.1: it fires for the initial request exactly as it does for every redirect hop, so a fetch can
 * never be steered off the allowlist mid-flight.
 *
 * Never constructed with response bodies or headers — only the offending URL, which is either the
 * URL a caller supplied or a `Location` header from an allowlisted host, neither of which can
 * carry PII in this module's usage.
 */
final class HostNotAllowedException extends RuntimeException
{
    public static function forUrl(string $url): self
    {
        return new self(sprintf('URL "%s" is not on the allowed host list for this source.', $url));
    }
}
