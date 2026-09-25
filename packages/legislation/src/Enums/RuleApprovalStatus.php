<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

/**
 * `fiscal_parameter_versions.status` / `tax_rule_versions.status` (`BLUEPRINT.md` §6.8). Shared
 * between both tables because the invariant it carries — "no agent may set `APPROVED`"
 * (`docs/AGENTS.md` §9, `BLUEPRINT.md` §9.3) — is the same stable domain rule for both, not
 * incidental similarity (`docs/CODE-QUALITY.md` §1). Enforced in code by
 * {@see \Kern\Legislation\Concerns\ForbidsAgentApproval}, applied to both
 * {@see \App\Domain\FiscalParameters\Models\FiscalParameterVersion} and
 * {@see \Kern\Legislation\Models\TaxRuleVersion}.
 */
enum RuleApprovalStatus: string
{
    case CANDIDATE = 'CANDIDATE';
    case APPROVED = 'APPROVED';
    case SUPERSEDED = 'SUPERSEDED';
    case REJECTED = 'REJECTED';
}
