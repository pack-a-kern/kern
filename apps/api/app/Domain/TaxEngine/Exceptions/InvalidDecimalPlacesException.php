<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use InvalidArgumentException;

final class InvalidDecimalPlacesException extends InvalidArgumentException
{
    public static function forValue(int $value): self
    {
        return new self(sprintf('Decimal places must be zero or positive, got %d.', $value));
    }
}
