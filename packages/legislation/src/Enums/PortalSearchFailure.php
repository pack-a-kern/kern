<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

use Kern\Legislation\Legislatie\Exceptions\PortalBrowserException;
use Kern\Legislation\Legislatie\Exceptions\PortalSearchRefusedException;
use Throwable;

/**
 * Why a portal search stopped short, as a code a screen words in its own language.
 *
 * {@see REFUSED}: the site's search page kept answering its error page, however often it was asked —
 * which it does at random, not to say nothing matched (a search with no result is an empty list).
 * Anything else the portal or the fetch threw — a status, a connection, an answer that is not what a
 * search answers — is {@see UNREACHABLE}.
 */
enum PortalSearchFailure: string
{
    case UNREACHABLE = 'portal_unreachable';

    case REFUSED = 'portal_search_refused';

    case BROWSER_BUSY = 'portal_browser_busy';

    case BROWSER_UNAVAILABLE = 'portal_browser_unavailable';

    public static function of(Throwable $exception): self
    {
        return match (true) {
            $exception instanceof PortalSearchRefusedException => self::REFUSED,
            $exception instanceof PortalBrowserException => $exception->reason === PortalBrowserException::BUSY
                ? self::BROWSER_BUSY
                : self::BROWSER_UNAVAILABLE,
            default => self::UNREACHABLE,
        };
    }
}
