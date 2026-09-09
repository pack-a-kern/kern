<?php

declare(strict_types=1);

use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Domain\TaxEngine\Enums\RuleApprovalStatus;
use App\Domain\TaxEngine\Exceptions\CandidateNotAllowedInFinalAssessmentException;
use App\Domain\TaxEngine\Services\ParameterResolver;
use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus as TaxKnowledgeRuleApprovalStatus;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Providers\EloquentFiscalParameterProvider;

/**
 * Constructed against the real `EloquentFiscalParameterProvider` adapter
 * (`docs/adr/0010-tax-engine-ports-and-adapters.md`) — `ParameterResolver` itself only ever sees
 * the `FiscalParameterProvider` port.
 */
it('allows a CANDIDATE parameter version in a DRAFT assessment', function (): void {
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.TEST.PARAM_DRAFT_OK']);
    FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-01-01',
        'status' => TaxKnowledgeRuleApprovalStatus::CANDIDATE,
    ]);

    $resolved = (new ParameterResolver(new EloquentFiscalParameterProvider()))
        ->resolve(['RO.TEST.PARAM_DRAFT_OK'], new DateTimeImmutable('2026-06-01'), AssessmentStatus::DRAFT);

    expect($resolved->get('RO.TEST.PARAM_DRAFT_OK')->status)->toBe(RuleApprovalStatus::CANDIDATE);
});

it('refuses a CANDIDATE parameter version in a FINAL assessment', function (): void {
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.TEST.PARAM_FINAL_BLOCKED']);
    FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-01-01',
        'status' => TaxKnowledgeRuleApprovalStatus::CANDIDATE,
    ]);

    expect(fn () => (new ParameterResolver(new EloquentFiscalParameterProvider()))
        ->resolve(['RO.TEST.PARAM_FINAL_BLOCKED'], new DateTimeImmutable('2026-06-01'), AssessmentStatus::FINAL))
        ->toThrow(CandidateNotAllowedInFinalAssessmentException::class);
});

it('allows an APPROVED parameter version in a FINAL assessment', function (): void {
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.TEST.PARAM_FINAL_OK']);
    $section = SourceSection::factory()->create();
    insertApprovedFiscalParameterVersion($parameter->id, $section->id, ['valid_from' => '2026-01-01']);

    $resolved = (new ParameterResolver(new EloquentFiscalParameterProvider()))
        ->resolve(['RO.TEST.PARAM_FINAL_OK'], new DateTimeImmutable('2026-06-01'), AssessmentStatus::FINAL);

    expect($resolved->get('RO.TEST.PARAM_FINAL_OK')->status)->toBe(RuleApprovalStatus::APPROVED);
});

it('resolves an empty parameter code list to an empty set', function (): void {
    $resolved = (new ParameterResolver(new EloquentFiscalParameterProvider()))
        ->resolve([], new DateTimeImmutable('2026-06-01'), AssessmentStatus::FINAL);

    expect($resolved->all())->toBe([]);
});
