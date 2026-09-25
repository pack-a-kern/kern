<?php

declare(strict_types=1);

namespace Kern\Platform\Approval;

use RuntimeException;

/**
 * `docs/AGENTS.md` §9 / `BLUEPRINT.md` §9.3: "No agent may set `APPROVED`." Thrown by
 * {@see \Kern\Platform\Approval\ForbidsAgentApproval} when application code attempts to
 * save a row with `status = APPROVED` — this is a hard stop, not a warning.
 */
final class AgentApprovalNotPermittedException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'Setting status to APPROVED on %s is not permitted from application code. '
                . 'A human review workflow (not yet built) must approve fiscal candidates.',
            $modelClass,
        ));
    }
}
