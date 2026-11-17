<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker\Exceptions;

use RuntimeException;

/**
 * A sequence named a frame — an embedded chart, a login form in an iframe — that the page never put
 * up. The run did not break; the page simply does not have that frame, which for some pages is the
 * ordinary case (a bond with no price chart) and not an error.
 *
 * Its own class so a caller can tell this apart from {@see BrowserRunFailedException} and treat a
 * missing frame as an answer — "there was nothing of that kind to read" — rather than a failure.
 */
final class BrowserFrameAbsentException extends RuntimeException
{
    private function __construct(public readonly string $frame)
    {
        parent::__construct(
            $frame === '' ? 'The page had none of the frame the sequence expected.' : "The page has no frame matching {$frame}.",
        );
    }

    public static function for(string $frame): self
    {
        return new self($frame);
    }
}
