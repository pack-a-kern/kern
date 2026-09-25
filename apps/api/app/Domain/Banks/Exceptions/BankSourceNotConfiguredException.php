<?php

declare(strict_types=1);

namespace App\Domain\Banks\Exceptions;

use RuntimeException;

/** Something the import needs is missing: a download page or a filter in configuration, or the current list the backfill builds on. */
final class BankSourceNotConfiguredException extends RuntimeException
{
    public static function endpoint(string $source, string $endpoint): self
    {
        return new self("config/sources.php has no \"{$endpoint}\" address for {$source}.");
    }

    public static function filter(string $key): self
    {
        return new self("config/banks.php \"{$key}\" is empty — the import would keep no banks.");
    }

    public static function nothingImported(): self
    {
        return new self('The bank list is empty: run kern:banks:import first, so the backfill knows which banks are current.');
    }
}
