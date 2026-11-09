<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing;

use RuntimeException;

/**
 * A source that did not read as expected: a table not found, a column missing, a value that is not
 * what its column says. The import that met it writes nothing (ADR 0025 §6), and the message says where
 * — the source, the row, the column — never the page itself.
 */
final class ParseFailure extends RuntimeException
{
    public static function at(string $where, string $what): self
    {
        return new self("{$where}: {$what}");
    }
}
