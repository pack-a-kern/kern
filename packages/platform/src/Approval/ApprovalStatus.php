<?php

declare(strict_types=1);

namespace Kern\Platform\Approval;

/**
 * Where a proposed version stands: offered, accepted by a person, replaced, or refused.
 *
 * In the platform because the invariant it carries — "no agent may set `APPROVED`"
 * (`docs/AGENTS.md` §9, `BLUEPRINT.md` §9.3) — is a governance rule, not a tax one, and two
 * different packages' tables need it: `tax_rule_versions.status` and
 * `fiscal_parameter_versions.status` (`BLUEPRINT.md` §6.8). It is the same stable rule for both, not
 * incidental similarity (`docs/CODE-QUALITY.md` §1). Enforced in code by
 * {@see ForbidsAgentApproval}.
 */
enum ApprovalStatus: string
{
    case CANDIDATE = 'CANDIDATE';
    case APPROVED = 'APPROVED';
    case SUPERSEDED = 'SUPERSEDED';
    case REJECTED = 'REJECTED';
}
