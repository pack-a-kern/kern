<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\Legislation\Legislatie\Exceptions\PortalBrowserException;
use Kern\Legislation\Legislatie\Exceptions\PortalSearchRefusedException;
use Throwable;

/**
 * Why a portal search stopped short, as a code a screen words in its own language.
 *
 * {@see REFUSED}: the site's search page kept answering its error page, however often it was asked —
 * which it does at random, not to say nothing matched (a search with no result is an empty list).
 * {@see SEARCH_DOWN}: the web service answered, with a server error, as often as it was asked — its own
 * search behind it down (2026-11-10: "Unable to connect to the remote server", every search tried),
 * which no retry here mends and which leaves the laws already read untouched. Anything else the portal
 * or the fetch threw — a connection, an answer that is not what a search answers — is {@see UNREACHABLE}.
 */
enum PortalSearchFailure: string
{
    case UNREACHABLE = 'portal_unreachable';

    case REFUSED = 'portal_search_refused';

    case SEARCH_DOWN = 'portal_search_down';

    case BROWSER_BUSY = 'portal_browser_busy';

    case BROWSER_UNAVAILABLE = 'portal_browser_unavailable';

    public static function of(Throwable $exception): self
    {
        return match (true) {
            $exception instanceof PortalSearchRefusedException => self::REFUSED,
            $exception instanceof UnexpectedStatusException && $exception->status >= 500 => self::SEARCH_DOWN,
            $exception instanceof PortalBrowserException => $exception->reason === PortalBrowserException::BUSY
                ? self::BROWSER_BUSY
                : self::BROWSER_UNAVAILABLE,
            default => self::UNREACHABLE,
        };
    }
}
