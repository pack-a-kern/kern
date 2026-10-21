<?php

declare(strict_types=1);

use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\CalculationStep;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\CalculationTrace;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxAssessmentLineResult;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxAssessmentResult;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxObligation;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine;
use Kern\TaxRomania\TaxEngine\Services\TaxAssessmentRecorder;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;

it('persists a computed assessment result as tax_assessments + tax_assessment_lines rows', function (): void {
    $subject = TaxSubject::factory()->create();
    $ruleVersion = TaxRuleVersion::factory()->create();

    $trace = new CalculationTrace(
        ruleCode: 'RO.TEST.RECORDER',
        ruleVersion: '0.0.1',
        inputs: ['taxable_base' => '1000.00 RON'],
        steps: [new CalculationStep('multiply', ['rate' => '0.10'], '100.00 RON')],
        sourceRefs: ['section-1'],
        result: '100.00 RON',
    );

    $line = new TaxAssessmentLineResult(
        taxCode: 'SYNTHETIC_TAX',
        categoryCode: 'SYNTHETIC_TEST_CATEGORY',
        taxableBase: Money::ofMinorUnits(100000, 'RON'),
        rate: DecimalRate::fromString('0.10'),
        tax: Money::ofMinorUnits(10000, 'RON'),
        legalRuleVersionId: $ruleVersion->id,
        explanationKey: 'synthetic.test.explanation',
        trace: $trace,
    );

    $result = new TaxAssessmentResult(
        taxSubjectId: $subject->id,
        fiscalYear: 2026,
        asOf: new DateTimeImmutable('2026-06-01'),
        rulesetId: 'RO-TEST-2026@0.0.1',
        status: AssessmentStatus::DRAFT,
        inputSnapshotHash: hash('sha256', 'synthetic'),
        lines: [$line],
        obligations: [new TaxObligation('SYNTHETIC_TAX', Money::ofMinorUnits(10000, 'RON'), [0])],
        totals: ['SYNTHETIC_TAX' => Money::ofMinorUnits(10000, 'RON')],
    );

    $assessment = (new TaxAssessmentRecorder())->record($result);

    expect(TaxAssessment::withoutGlobalScope(TenantScope::class)->count())->toBe(1)
        ->and(TaxAssessmentLine::query()->count())->toBe(1);

    $persistedAssessment = TaxAssessment::withoutGlobalScope(TenantScope::class)->findOrFail($assessment->id);
    $totals = $persistedAssessment->totals_json;
    assert(is_array($totals['SYNTHETIC_TAX']));

    expect($persistedAssessment->tax_subject_id)->toBe($subject->id)
        ->and($persistedAssessment->status)->toBe(AssessmentStatus::DRAFT)
        ->and($totals['SYNTHETIC_TAX']['minor_units'])->toBe(10000)
        ->and($persistedAssessment->calculation_trace_object_path)->toBeNull();

    $persistedLine = TaxAssessmentLine::query()->where('assessment_id', $assessment->id)->firstOrFail();
    $persistedTrace = $persistedLine->calculation_trace_json;
    assert(is_array($persistedTrace['steps']));
    assert(is_array($persistedTrace['steps'][0]));

    expect($persistedLine->tax_minor)->toBe(10000)
        ->and($persistedLine->rate_decimal)->toBe('0.10')
        ->and($persistedLine->legal_rule_version_id)->toBe($ruleVersion->id)
        ->and($persistedTrace['result'])->toBe('100.00 RON')
        ->and($persistedTrace['steps'][0]['operation'])->toBe('multiply');
});
