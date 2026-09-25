<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Exceptions;

use RuntimeException;

final class AssessmentNotFoundException extends RuntimeException
{
    public static function forId(string $assessmentId): self
    {
        return new self("No tax_assessments row exists with id {$assessmentId}.");
    }
}
