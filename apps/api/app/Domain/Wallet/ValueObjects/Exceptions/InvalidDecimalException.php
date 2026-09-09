<?php

declare(strict_types=1);

namespace App\Domain\Wallet\ValueObjects\Exceptions;

use InvalidArgumentException;

final class InvalidDecimalException extends InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self("\"{$value}\" is not a valid non-negative decimal string.");
    }

    public static function forNonPositiveDivisor(): self
    {
        return new self('Divisor must be a positive value.');
    }
}
