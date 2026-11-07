<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker\Exceptions;

use RuntimeException;

/** The worker answered 501: what was asked is not built — not the same fact as not reachable. */
final class BrowserActionNotBuiltException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The browser worker does not implement this action.');
    }
}
