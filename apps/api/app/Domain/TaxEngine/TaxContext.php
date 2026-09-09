<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine;

use App\Domain\TaxEngine\DataTransferObjects\TaxFactSnapshot;
use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Domain\TaxEngine\Exceptions\IncompleteTaxContextException;
use DateTimeImmutable;

/**
 * `BLUEPRINT.md` §11.1 — `TaxContext::forSubject($subject)->fiscalYear(2025)->asOf($date)->
 * withFacts($facts)`. A readonly, immutable builder: every `with*()`/`for*()` call returns a new
 * instance, never mutates in place, so a partially-built context can be safely reused as a
 * starting point for several assessments.
 *
 * `forRules()` is this module's own addition to the literal builder chain — `BLUEPRINT.md`'s example
 * does not say how the engine learns which rules to evaluate, and inferring "which `tax_rules`
 * apply to these facts" from `category_code` would itself be an un-sourced fiscal mapping decision
 * (`docs/AGENTS.md` rule 3) this task has no approved data to make. The caller states explicitly
 * which rule codes to assess; each resolved rule receives every fact in the context and decides
 * for itself which ones it needs (`TaxRuleContract`'s own contract).
 *
 * Carries no database connection, no clock, no config — it is pure data, assembled by whichever
 * caller (a controller, a job) already has authorization/subject context; see
 * `docs/handoff/020-tax-engine.md` for how that authorization is expected to have already
 * happened before a `TaxContext` is ever built.
 */
final readonly class TaxContext
{
    /**
     * @param list<string> $ruleCodes
     * @param list<TaxFactSnapshot> $facts
     */
    private function __construct(
        public ?string $taxSubjectId,
        public ?int $fiscalYear,
        public ?DateTimeImmutable $asOf,
        public array $ruleCodes,
        public array $facts,
        public AssessmentStatus $status,
        public string $rulesetId,
    ) {
    }

    public static function forSubject(string $taxSubjectId): self
    {
        return new self($taxSubjectId, null, null, [], [], AssessmentStatus::DRAFT, '');
    }

    public function fiscalYear(int $fiscalYear): self
    {
        return new self($this->taxSubjectId, $fiscalYear, $this->asOf, $this->ruleCodes, $this->facts, $this->status, $this->rulesetId);
    }

    public function asOf(DateTimeImmutable $asOf): self
    {
        return new self($this->taxSubjectId, $this->fiscalYear, $asOf, $this->ruleCodes, $this->facts, $this->status, $this->rulesetId);
    }

    /**
     * @param list<string> $ruleCodes
     */
    public function forRules(array $ruleCodes): self
    {
        return new self($this->taxSubjectId, $this->fiscalYear, $this->asOf, $ruleCodes, $this->facts, $this->status, $this->rulesetId);
    }

    /**
     * @param list<TaxFactSnapshot> $facts
     */
    public function withFacts(array $facts): self
    {
        return new self($this->taxSubjectId, $this->fiscalYear, $this->asOf, $this->ruleCodes, $facts, $this->status, $this->rulesetId);
    }

    public function draft(): self
    {
        return new self($this->taxSubjectId, $this->fiscalYear, $this->asOf, $this->ruleCodes, $this->facts, AssessmentStatus::DRAFT, $this->rulesetId);
    }

    public function final(): self
    {
        return new self($this->taxSubjectId, $this->fiscalYear, $this->asOf, $this->ruleCodes, $this->facts, AssessmentStatus::FINAL, $this->rulesetId);
    }

    public function withRulesetId(string $rulesetId): self
    {
        return new self($this->taxSubjectId, $this->fiscalYear, $this->asOf, $this->ruleCodes, $this->facts, $this->status, $rulesetId);
    }

    /**
     * Fails loudly rather than letting `App\Domain\TaxEngine\Services\TaxAssessor` proceed with a
     * silently-null subject/year/date.
     */
    public function assertComplete(): void
    {
        if ($this->taxSubjectId === null) {
            throw IncompleteTaxContextException::missing('taxSubjectId');
        }

        if ($this->fiscalYear === null) {
            throw IncompleteTaxContextException::missing('fiscalYear');
        }

        if ($this->asOf === null) {
            throw IncompleteTaxContextException::missing('asOf');
        }

        if ($this->ruleCodes === []) {
            throw IncompleteTaxContextException::missing('ruleCodes');
        }
    }
}
