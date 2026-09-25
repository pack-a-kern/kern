<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Enums;

/**
 * TaxEngine's own view of a `tax_rule_versions`/`fiscal_parameter_versions` row's approval state
 * — carried on {@see \Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion} and
 * {@see \Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter} so
 * {@see \Kern\TaxRomania\TaxEngine\Services\RuleResolver} / {@see \Kern\TaxRomania\TaxEngine\Services\ParameterResolver}
 * can enforce `docs/AGENTS.md` §9's draft-vs-final gate.
 *
 * Deliberately a second enum, not a reuse of `Kern\Platform\Approval\ApprovalStatus` —
 * see `docs/adr/0010-tax-engine-ports-and-adapters.md`. A TaxEngine port
 * ({@see \Kern\TaxRomania\TaxEngine\Contracts\TaxRuleVersionProvider}, {@see \Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider})
 * must never name a TaxKnowledge type in its signature, or the module boundary that ADR restores
 * leaks straight back in through an enum instead of a model. `Kern\TaxRomania\FiscalParameters\Providers\
 * EloquentTaxRuleVersionProvider`/`EloquentFiscalParameterProvider` map TaxKnowledge's enum to
 * this one at the adapter boundary (`self::from($tkStatus->value)`) — the case names and backing
 * values are, and must remain, identical between the two enums.
 */
enum RuleApprovalStatus: string
{
    case CANDIDATE = 'CANDIDATE';
    case APPROVED = 'APPROVED';
    case SUPERSEDED = 'SUPERSEDED';
    case REJECTED = 'REJECTED';
}
