<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\DataTransferObjects;

use DateTimeImmutable;

/**
 * The complete, explicit input to `Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract::evaluate()`.
 * Every fact this project's purity requirement (`BLUEPRINT.md` §11.2, ADR 0004) demands a rule
 * receive rather than fetch — the subject's facts, the assessment date, and every resolved
 * parameter — is here; nothing else reaches a rule.
 */
final readonly class TaxRuleInput
{
    /**
     * @param list<TaxFactSnapshot> $facts
     */
    public function __construct(
        public int $fiscalYear,
        public DateTimeImmutable $asOf,
        public array $facts,
        public ResolvedParameterSet $parameters,
        public ResolvedTaxRuleVersion $ruleVersion,
    ) {
    }
}
