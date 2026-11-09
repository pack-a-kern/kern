<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

/**
 * An approved value says the law is not what a rule was written for — an exemption answered no — so the
 * rule refuses to compute rather than give a figure the law no longer supports.
 */
final class TaxRuleCannotComputeException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("The tax rule cannot compute: {$reason}.");
    }
}
