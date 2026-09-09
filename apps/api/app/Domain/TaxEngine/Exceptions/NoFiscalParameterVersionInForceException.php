<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use DateTimeImmutable;
use RuntimeException;

final class NoFiscalParameterVersionInForceException extends RuntimeException
{
    public static function forDate(string $parameterCode, DateTimeImmutable $date): self
    {
        return new self(sprintf('No fiscal parameter version for "%s" is in force on %s.', $parameterCode, $date->format('Y-m-d')));
    }
}
