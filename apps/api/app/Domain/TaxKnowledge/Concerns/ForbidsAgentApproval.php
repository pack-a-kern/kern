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
 * The one way a row becomes `APPROVED` is {@see savedAsApprovedByPerson()}, through the model's own
 * `approveByPerson()`: it names the person, and only the save it makes is let through. Its one
 * caller is
 * `App\Domain\TaxKnowledge\FiscalParameters\Actions\ApproveFiscalParameterVersion`, reached only
 * from an authenticated request holding `fiscal.parameters.approve` — never from a job, a command or
 * a model's output. The database refuses an `APPROVED` row that names no one.
 */
trait ForbidsAgentApproval
{
    /** The row a person is approving right now — the only one the listener lets through as `APPROVED`. */
    private static ?string $approvingByPerson = null;

    protected static function bootForbidsAgentApproval(): void
    {
        static::saving(function (self $model): void {
            /** @var RuleApprovalStatus|string|null $status */
            $status = $model->getAttribute('status');
            $approved = $status === RuleApprovalStatus::APPROVED || $status === RuleApprovalStatus::APPROVED->value;

            if ($approved && (self::$approvingByPerson === null || self::$approvingByPerson !== $model->getKey())) {
                throw AgentApprovalNotPermittedException::forModel(static::class);
            }
        });
    }

    /**
     * Saves this row as `APPROVED` with the attributes that name who decided it — the one save the
     * listener lets through, and only for this row.
     *
     * @param  array<string, mixed>  $decidedBy  the model's own columns for who approved it, and when
     */
    protected function savedAsApprovedByPerson(array $decidedBy): void
    {
        $key = $this->getKey();
        self::$approvingByPerson = is_string($key) ? $key : null;

        try {
            $this->forceFill(['status' => RuleApprovalStatus::APPROVED->value, ...$decidedBy])->save();
        } finally {
            self::$approvingByPerson = null;
        }
    }
}
