<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Services;

use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleInput;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleOutcome;
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidRuleImplementationException;

/**
 * Instantiates a `tax_rule_versions.implementation_class` and calls it — deliberately via `new
 * $class()`, never the container: `docs/AGENTS.md` §11.2/ADR 0004 requires a rule to be reachable
 * with zero injected dependencies beyond `$input`, so the container is never given a chance to
 * hand a rule anything else (a database connection, an HTTP client, …).
 */
final readonly class RuleInvoker
{
    public function invoke(ResolvedTaxRuleVersion $ruleVersion, TaxRuleInput $input): TaxRuleOutcome
    {
        $className = $ruleVersion->implementationClass;

        if ($className === null) {
            throw InvalidRuleImplementationException::forMissingClass($ruleVersion->id);
        }

        if (! class_exists($className)) {
            throw InvalidRuleImplementationException::forUnknownClass($ruleVersion->id, $className);
        }

        if (! is_a($className, TaxRuleContract::class, true)) {
            throw InvalidRuleImplementationException::forNonConformingClass($ruleVersion->id, $className);
        }

        /** @var TaxRuleContract $rule */
        $rule = new $className();

        return $rule->evaluate($input);
    }
}
