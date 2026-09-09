<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use RuntimeException;

final class UnknownTaxRuleException extends RuntimeException
{
    public static function forCode(string $ruleCode): self
    {
        return new self(sprintf('No tax_rules row with code "%s".', $ruleCode));
    }
}
