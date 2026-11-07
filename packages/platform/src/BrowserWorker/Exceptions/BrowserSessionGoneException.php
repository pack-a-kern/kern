<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker\Exceptions;

use RuntimeException;

/**
 * The worker has no such session: it expired at its own TTL, or the worker restarted and lost every
 * browser it held. Raised for a run in a session only — a 404 closing one is a browser already gone,
 * which is what closing wanted.
 */
final class BrowserSessionGoneException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The browser session no longer exists.');
    }
}
