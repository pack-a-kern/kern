<?php

declare(strict_types=1);

namespace App\Domain\Rental\Exceptions;

use InvalidArgumentException;

final class InvalidPaymentAmountException extends InvalidArgumentException
{
    public static function forAmount(int $amountMinor): self
    {
        return new self("A rental payment amount must be positive, got {$amountMinor} minor units.");
    }
}
