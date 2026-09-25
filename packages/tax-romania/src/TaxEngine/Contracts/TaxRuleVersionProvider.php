<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Contracts;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousTaxRuleVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRuleVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownTaxRuleException;

/**
 * The port {@see \Kern\TaxRomania\TaxEngine\Services\RuleResolver} depends on to resolve the
 * `tax_rule_versions` row in force for a rule code and date. Expressed purely in TaxEngine's own
 * DTOs, exceptions, and scalars — never a TaxKnowledge type — so this module never needs to know
 * (or import) how or where the ruleset is actually stored.
 *
 * `Kern\TaxRomania\FiscalParameters\Providers\EloquentTaxRuleVersionProvider` is, today, the only
 * implementation — bound in `Kern\Legislation\TaxKnowledgeServiceProvider`. See
 * `docs/adr/0010-tax-engine-ports-and-adapters.md`: TaxKnowledge depends on this interface
 * (dependency inversion), TaxEngine depends on nothing from TaxKnowledge.
 *
 * Contract, unchanged from the direct-read implementation it replaces
 * (`docs/adr/0009-tax-engine-reads-tax-knowledge-models-directly.md`, now superseded): exactly
 * one matching version for a date, or a named exception — never a heuristic.
 * `REJECTED`/`SUPERSEDED` versions are excluded from the candidate set entirely.
 */
interface TaxRuleVersionProvider
{
    /**
     * @throws UnknownTaxRuleException no `tax_rules` row carries this code
     * @throws NoTaxRuleVersionInForceException zero CANDIDATE/APPROVED versions cover the date
     * @throws AmbiguousTaxRuleVersionException more than one version's effective range covers the date
     */
    public function versionInForceOn(string $ruleCode, DateTimeImmutable $date): ResolvedTaxRuleVersion;
}
