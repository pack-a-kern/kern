<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\ValueObjects\Exceptions;

use InvalidArgumentException;

final class InvalidMoneyException extends InvalidArgumentException
{
    public static function forCurrencyCode(string $currencyCode): self
    {
        return new self("\"{$currencyCode}\" is not a valid ISO 4217 alphabetic currency code (expected exactly 3 uppercase letters).");
    }
}
