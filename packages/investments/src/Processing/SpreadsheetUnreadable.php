<?php

declare(strict_types=1);

namespace Kern\Investments\Processing;

use RuntimeException;
use Throwable;

/** A workbook the processing service did not turn into a grid: it refused it, or did not answer as it should. */
final class SpreadsheetUnreadable extends RuntimeException
{
    public static function because(string $why, ?Throwable $previous = null): self
    {
        return new self("The spreadsheet could not be read: {$why}.", 0, $previous);
    }
}
