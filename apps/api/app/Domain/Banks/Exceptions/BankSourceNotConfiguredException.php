<?php

declare(strict_types=1);

namespace App\Domain\Banks\Exceptions;

use RuntimeException;

/** A download page or a filter the import needs is missing from configuration. */
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
}
