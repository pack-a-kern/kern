<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

final class UnknownFiscalParameterException extends RuntimeException
{
    public static function forCode(string $parameterCode): self
    {
        return new self(sprintf('No fiscal_parameters row with code "%s".', $parameterCode));
    }
}
