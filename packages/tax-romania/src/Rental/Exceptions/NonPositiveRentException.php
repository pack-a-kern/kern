<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Exceptions;

use InvalidArgumentException;

final class NonPositiveRentException extends InvalidArgumentException
{
    public static function forAmount(int $rentMinor): self
    {
        return new self("Rent must be a positive amount, got {$rentMinor} minor units.");
    }
}
