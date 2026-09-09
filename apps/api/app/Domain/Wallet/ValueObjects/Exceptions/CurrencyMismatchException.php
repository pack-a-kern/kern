<?php

declare(strict_types=1);

namespace App\Domain\Wallet\ValueObjects\Exceptions;

use InvalidArgumentException;

final class CurrencyMismatchException extends InvalidArgumentException
{
    public static function forOperation(string $left, string $right): self
    {
        return new self("Cannot combine {$left} and {$right} amounts — Money arithmetic requires matching currency codes.");
    }
}
