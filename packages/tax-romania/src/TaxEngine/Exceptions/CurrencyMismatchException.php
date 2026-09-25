<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

/**
 * `Kern\TaxRomania\TaxEngine\ValueObjects\Money` never silently coerces two different currencies (or
 * two different minor-unit scales of the same currency) together — `BLUEPRINT.md` §11.3/§30 requires
 * every fiscal figure to be traceable and reproducible, which a silent cross-currency add would
 * break invisibly.
 */
final class CurrencyMismatchException extends RuntimeException
{
    public static function forOperation(string $left, string $right): self
    {
        return new self(sprintf('Cannot combine money in "%s" with money in "%s".', $left, $right));
    }
}
