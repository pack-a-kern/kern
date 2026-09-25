<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use InvalidArgumentException;

/**
 * `Kern\TaxRomania\TaxEngine\ValueObjects\Money` requires a 3-letter alphabetic currency code
 * (ISO 4217 shape — not validated against a real currency list, the same "shape, not membership"
 * choice `Kern\TaxRomania\Fx\Parsing\BnrXmlParser` makes for the same reason: this project has no
 * authoritative currency-list source to validate membership against without fabricating one).
 */
final class InvalidCurrencyCodeException extends InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('"%s" is not a valid 3-letter currency code.', $value));
    }
}
