<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Concerns;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\Exceptions\AgentApprovalNotPermittedException;

/**
 * Enforces `docs/AGENTS.md` §9 / `BLUEPRINT.md` §9.3 — "No agent may set `APPROVED`" — **in code**,
 * not only by review, on any model whose `status` attribute is cast to
 * {@see RuleApprovalStatus}. Registers a `saving` listener (Eloquent auto-discovers
 * `boot{TraitName}()` for every trait a model uses) that refuses to persist a row whose `status`
 * is `APPROVED`, regardless of whether that came from `create()`, `fill()`, or direct attribute
 * assignment.
 *
 * Shared between {@see \App\Domain\TaxKnowledge\Models\FiscalParameterVersion} and
 * {@see \App\Domain\TaxKnowledge\Models\TaxRuleVersion} because the invariant is identical, stable
 * domain knowledge (`docs/CODE-QUALITY.md` §1), not incidental similarity.
 *
 * This makes `APPROVED` **unreachable from this module's application code entirely** — there is
 * no escape hatch, because no human-review workflow exists yet to need one (see
 * `docs/handoff/018-legislation-ingestion.md` "Open questions" for how a future one might grant
 * itself a narrower, audited exception).
 */
trait ForbidsAgentApproval
{
    protected static function bootForbidsAgentApproval(): void
    {
        static::saving(function (self $model): void {
            /** @var RuleApprovalStatus|string|null $status */
            $status = $model->getAttribute('status');

            if ($status === RuleApprovalStatus::APPROVED || $status === RuleApprovalStatus::APPROVED->value) {
                throw AgentApprovalNotPermittedException::forModel(static::class);
            }
        });
    }
}
