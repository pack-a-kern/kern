<?php

declare(strict_types=1);

namespace App\Domain\Fx\Exceptions;

use RuntimeException;

final class InvalidDecimalRateException extends RuntimeException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('"%s" is not a valid non-negative decimal string.', $value));
    }
}
