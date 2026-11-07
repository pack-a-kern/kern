<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker\Exceptions;

use RuntimeException;

/**
 * The page a `source` run opened was not handed back: its server answered with an error status, or it
 * was larger than the worker hands back. Each is what a download of the same page would have met, and
 * a caller downloading pages maps it to its own word for the same thing.
 */
final class BrowserPageRefusedException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $pageStatus, public readonly ?int $limitBytes)
    {
        parent::__construct($message);
    }

    public static function status(int $status): self
    {
        return new self("The page answered with HTTP status {$status}.", $status, null);
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self("The page is larger than the {$limitBytes} bytes the browser worker hands back.", null, $limitBytes);
    }
}
