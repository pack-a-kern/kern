<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use InvalidArgumentException;

final class IncompleteTaxContextException extends InvalidArgumentException
{
    public static function missing(string $field): self
    {
        return new self(sprintf('TaxContext is missing required field "%s".', $field));
    }
}
