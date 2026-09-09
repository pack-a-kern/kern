<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use RuntimeException;

/**
 * `tax_rule_versions.implementation_class` is null, does not exist, or does not implement
 * `App\Domain\TaxEngine\Contracts\TaxRuleContract` — a data/deployment defect, never silently
 * skipped or worked around (`docs/AGENTS.md` §28 rule 8 — never disable a check to make something
 * pass).
 */
final class InvalidRuleImplementationException extends RuntimeException
{
    public static function forMissingClass(string $ruleVersionId): self
    {
        return new self(sprintf('Tax rule version "%s" has no implementation_class configured.', $ruleVersionId));
    }

    public static function forUnknownClass(string $ruleVersionId, string $className): self
    {
        return new self(sprintf('Tax rule version "%s" declares implementation_class "%s", which does not exist.', $ruleVersionId, $className));
    }

    public static function forNonConformingClass(string $ruleVersionId, string $className): self
    {
        return new self(sprintf(
            'Tax rule version "%s" declares implementation_class "%s", which does not implement %s.',
            $ruleVersionId,
            $className,
            \App\Domain\TaxEngine\Contracts\TaxRuleContract::class,
        ));
    }
}
