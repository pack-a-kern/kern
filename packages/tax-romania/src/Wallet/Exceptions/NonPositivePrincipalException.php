<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;

final class NonPositivePrincipalException extends InvalidArgumentException
{
    public static function forAmount(int $principalMinor): self
    {
        return new self("A deposit's principal must be positive, got {$principalMinor}.");
    }
}
