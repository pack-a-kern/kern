<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie\Exceptions;

use RuntimeException;

/**
 * The site's search page answered with its error page.
 *
 * It answers a search with no result the same way as a search it could not run — the same status, the
 * same page, seen on 2026-11-07 — so this claims neither: the person is told both are possible, rather
 * than that nothing matched while the site was down, or that the site was down when nothing matched.
 */
final class PortalSearchRefusedException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The portal answered the search with its error page: nothing matched, or its search failed.');
    }
}
