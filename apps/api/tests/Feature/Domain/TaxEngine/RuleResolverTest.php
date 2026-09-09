<?php

declare(strict_types=1);

use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Domain\TaxEngine\Enums\RuleApprovalStatus;
use App\Domain\TaxEngine\Exceptions\CandidateNotAllowedInFinalAssessmentException;
use App\Domain\TaxEngine\Services\RuleResolver;
use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus as TaxKnowledgeRuleApprovalStatus;
use App\Domain\TaxKnowledge\Models\TaxRule;
use App\Domain\TaxKnowledge\Models\TaxRuleVersion;
use App\Domain\TaxKnowledge\Providers\EloquentTaxRuleVersionProvider;

/**
 * `docs/AGENTS.md` §9 / `BLUEPRINT.md` §9.3 enforced at the assessment boundary — the task brief's
 * explicit requirement: "a rule whose parameters are only CANDIDATE must be usable in a draft
 * assessment while being refused for a final one." Constructed against the real
 * `EloquentTaxRuleVersionProvider` adapter (`docs/adr/0010-tax-engine-ports-and-adapters.md`) —
 * `RuleResolver` itself only ever sees the `TaxRuleVersionProvider` port.
 */
it('allows a CANDIDATE version to back a DRAFT assessment', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.DRAFT_OK']);
    TaxRuleVersion::factory()->create([
        'tax_rule_id' => $rule->id,
        'valid_from' => '2026-01-01',
        'status' => TaxKnowledgeRuleApprovalStatus::CANDIDATE,
    ]);

    $resolved = (new RuleResolver(new EloquentTaxRuleVersionProvider()))
        ->resolve('RO.TEST.DRAFT_OK', new DateTimeImmutable('2026-06-01'), AssessmentStatus::DRAFT);

    expect($resolved->status)->toBe(RuleApprovalStatus::CANDIDATE);
});

it('refuses a CANDIDATE version for a FINAL assessment', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.FINAL_BLOCKED']);
    TaxRuleVersion::factory()->create([
        'tax_rule_id' => $rule->id,
        'valid_from' => '2026-01-01',
        'status' => TaxKnowledgeRuleApprovalStatus::CANDIDATE,
    ]);

    expect(fn () => (new RuleResolver(new EloquentTaxRuleVersionProvider()))
        ->resolve('RO.TEST.FINAL_BLOCKED', new DateTimeImmutable('2026-06-01'), AssessmentStatus::FINAL))
        ->toThrow(CandidateNotAllowedInFinalAssessmentException::class);
});

it('allows an APPROVED version to back a FINAL assessment', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.FINAL_OK']);
    insertApprovedTaxRuleVersion($rule->id, ['valid_from' => '2026-01-01']);

    $resolved = (new RuleResolver(new EloquentTaxRuleVersionProvider()))
        ->resolve('RO.TEST.FINAL_OK', new DateTimeImmutable('2026-06-01'), AssessmentStatus::FINAL);

    expect($resolved->status)->toBe(RuleApprovalStatus::APPROVED);
});

it('also allows an APPROVED version to back a DRAFT assessment', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.DRAFT_APPROVED']);
    insertApprovedTaxRuleVersion($rule->id, ['valid_from' => '2026-01-01']);

    $resolved = (new RuleResolver(new EloquentTaxRuleVersionProvider()))
        ->resolve('RO.TEST.DRAFT_APPROVED', new DateTimeImmutable('2026-06-01'), AssessmentStatus::DRAFT);

    expect($resolved->status)->toBe(RuleApprovalStatus::APPROVED);
});
