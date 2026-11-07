<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker\Exceptions;

use RuntimeException;

/**
 * The worker ran the sequence and could not finish it — a selector that never appeared, a page that
 * timed out — and said why, in words it has already redacted.
 *
 * Not the worker being unreachable, which is what a status code read alone used to make of it. The
 * worker's own words are kept apart from the message, because they may be empty and each caller says
 * the empty case in its own way.
 */
final class BrowserRunFailedException extends RuntimeException
{
    private function __construct(public readonly string $workerMessage)
    {
        parent::__construct($workerMessage === '' ? 'The browser could not finish the run.' : $workerMessage);
    }

    public static function because(string $workerMessage): self
    {
        return new self($workerMessage);
    }
}
