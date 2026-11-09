<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

/** Whether a title pattern compiles — a delimited PCRE pattern, as `/…/u`. */
final class LawWatchPattern
{
    public static function compiles(mixed $pattern): bool
    {
        if (! is_string($pattern) || $pattern === '') {
            return false;
        }

        // A pattern that does not compile answers false, and warns: the warning is the answer, not noise.
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
