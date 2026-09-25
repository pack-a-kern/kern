<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

final class MissingRequiredParameterException extends RuntimeException
{
    public static function forCode(string $parameterCode): self
    {
        return new self(sprintf('Rule requested fiscal parameter "%s" but it was not resolved into the input set.', $parameterCode));
    }
}
