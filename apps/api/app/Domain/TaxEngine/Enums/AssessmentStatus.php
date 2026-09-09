<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Enums;

/**
 * `tax_assessments.status` (`BLUEPRINT.md` §6.7, §12.3). Governs which
 * `App\Domain\TaxKnowledge\Enums\RuleApprovalStatus` a `tax_rule_versions`/
 * `fiscal_parameter_versions` row must carry to be usable: `DRAFT` accepts `CANDIDATE` or
 * `APPROVED`; `FINAL` accepts only `APPROVED` (`docs/AGENTS.md` §9 — "no agent may set
 * `APPROVED`", enforced upstream by `App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval`; this
 * enum is what makes that approval gate matter to an assessment, not just to the ruleset table).
 * See `App\Domain\TaxEngine\Services\RuleResolver`/`ParameterResolver`.
 */
enum AssessmentStatus: string
{
    case DRAFT = 'DRAFT';
    case FINAL = 'FINAL';
}
