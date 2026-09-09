<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use RuntimeException;

final class InvalidParameterValueShapeException extends RuntimeException
{
    public static function forParameter(string $parameterCode, string $reason): self
    {
        return new self(sprintf('Fiscal parameter "%s" has an invalid value_json shape: %s.', $parameterCode, $reason));
    }
}
