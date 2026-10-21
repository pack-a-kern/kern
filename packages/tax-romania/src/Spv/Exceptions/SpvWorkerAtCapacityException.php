<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Exceptions;

use RuntimeException;

/**
 * The browser worker answered, and is full: as many browsers open as it allows, or as many runs
 * waiting as it will queue (`503 at_capacity`).
 *
 * Not {@see SpvWorkerUnavailableException}, which it used to arrive as — "the browser worker is not
 * reachable" sent a person looking for a network fault while the worker was working perfectly. This
 * one passes on its own: wait, and try again.
 */
final class SpvWorkerAtCapacityException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The SPV browser worker is at capacity. Try again shortly.');
    }
}
