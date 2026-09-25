<?php

declare(strict_types=1);

namespace Kern\Legislation\Fetching\Exceptions;

use RuntimeException;

/**
 * `BLUEPRINT.md` §14.2's decompression-bomb / oversized-response protection, applied to source
 * fetching. Fires both on an over-limit `Content-Length` header (before any body is read) and on
 * the actual downloaded byte count (defense in depth against a missing or lying header).
 */
final class FetchSizeExceededException extends RuntimeException
{
    public static function forUrl(string $url, int $limitBytes): self
    {
        return new self(sprintf('Response for "%s" exceeded the %d byte size limit.', $url, $limitBytes));
    }
}
