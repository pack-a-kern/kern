<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use DateTimeImmutable;
use RuntimeException;

final class AmbiguousFiscalParameterVersionException extends RuntimeException
{
    /**
     * @param list<string> $versionIds
     */
    public static function forDate(string $parameterCode, DateTimeImmutable $date, array $versionIds): self
    {
        return new self(sprintf(
            'Overlapping effective ranges for fiscal parameter "%s" on %s: versions [%s]. Fix the data — never guess which applies.',
            $parameterCode,
            $date->format('Y-m-d'),
            implode(', ', $versionIds),
        ));
    }
}
