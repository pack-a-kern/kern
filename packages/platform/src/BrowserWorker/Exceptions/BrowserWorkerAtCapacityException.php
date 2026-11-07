<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker\Exceptions;

use RuntimeException;

/** Every browser the worker may open is open, or its queue is full. It passes on its own. */
final class BrowserWorkerAtCapacityException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The browser worker is at capacity. Try again shortly.');
    }
}
