<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use InvalidArgumentException;

final class IncompleteTaxContextException extends InvalidArgumentException
{
    public static function missing(string $field): self
    {
        return new self(sprintf('TaxContext is missing required field "%s".', $field));
    }
}
