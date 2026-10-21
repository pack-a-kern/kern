<?php

declare(strict_types=1);

use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Kern\TaxRomania\FiscalParameters\Providers\EloquentFiscalParameterProvider;
use Kern\TaxRomania\FiscalParameters\Providers\EloquentTaxRuleVersionProvider;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Enums\TaxFactStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\CandidateNotAllowedInFinalAssessmentException;
use Kern\TaxRomania\TaxEngine\Models\TaxFact;
use Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository;
use Kern\TaxRomania\TaxEngine\Services\ParameterResolver;
use Kern\TaxRomania\TaxEngine\Services\RuleInvoker;
use Kern\TaxRomania\TaxEngine\Services\RuleResolver;
use Kern\TaxRomania\TaxEngine\Services\TaxAssessor;
use Kern\TaxRomania\TaxEngine\TaxContext;
use Kern\TaxRomania\TaxRules\TaxRule;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;
use Tests\Fixtures\TaxEngine\Rules\SyntheticPercentageOfBaseRule;

/**
 * `BLUEPRINT.md` §11.1/§39 end to end, entirely on SYNTHETIC, clearly-fake data
 * (`docs/AGENTS.md` §2) — `SyntheticPercentageOfBaseRule` reads its one number from a
 * `fiscal_parameter_versions` row this test creates itself, never a real Romanian rate.
 */

function makeTaxAssessor(): TaxAssessor
{
    return new TaxAssessor(
        new RuleResolver(new EloquentTaxRuleVersionProvider()),
        new ParameterResolver(new EloquentFiscalParameterProvider()),
        new RuleInvoker(),
    );
}

/**
 * @return array{0: TaxSubject, 1: string}
 */
function seedSyntheticAssessmentFixture(ApprovalStatus $status): array
{
    $subject = TaxSubject::factory()->create();

    $rule = TaxRule::factory()->create([
        'code' => 'RO.TEST.SYNTHETIC_PERCENTAGE_OF_BASE',
        'tax_code' => 'SYNTHETIC_TAX',
    ]);

    $parameter = FiscalParameter::factory()->create([
        'code' => 'RO.TEST.SYNTHETIC_RATE',
        'value_type' => FiscalParameterValueType::DECIMAL,
    ]);

    if ($status === ApprovalStatus::APPROVED) {
        insertApprovedTaxRuleVersion($rule->id, [
            'valid_from' => '2026-01-01',
            'implementation_class' => SyntheticPercentageOfBaseRule::class,
            'config_json' => json_encode(['parameter_references' => ['RO.TEST.SYNTHETIC_RATE']], JSON_THROW_ON_ERROR),
        ]);

        $section = SourceSection::factory()->create();
        insertApprovedFiscalParameterVersion($parameter->id, $section->id, [
            'valid_from' => '2026-01-01',
            'value_json' => json_encode(['value' => '0.10'], JSON_THROW_ON_ERROR),
        ]);
    } else {
        TaxRuleVersion::factory()->create([
            'tax_rule_id' => $rule->id,
            'valid_from' => '2026-01-01',
            'implementation_class' => SyntheticPercentageOfBaseRule::class,
            'config_json' => ['parameter_references' => ['RO.TEST.SYNTHETIC_RATE']],
            'status' => $status,
        ]);

        FiscalParameterVersion::factory()->create([
            'fiscal_parameter_id' => $parameter->id,
            'valid_from' => '2026-01-01',
            'value_json' => ['value' => '0.10'],
            'status' => $status,
        ]);
    }

    return [$subject, $rule->id];
}

it('assesses a synthetic percentage-of-base rule end to end and produces a trace that re-derives the result', function (): void {
    [$subject] = seedSyntheticAssessmentFixture(ApprovalStatus::CANDIDATE);

    $fact = TaxFact::factory()->create([
        'tax_subject_id' => $subject->id,
        'ron_amount_minor' => 100000, // 1000.00 RON
        'status' => TaxFactStatus::CONFIRMED,
    ]);

    $context = TaxContext::forSubject($subject->id)
        ->fiscalYear(2026)
        ->asOf(new DateTimeImmutable('2026-06-01'))
        ->forRules(['RO.TEST.SYNTHETIC_PERCENTAGE_OF_BASE'])
        ->withRulesetId('RO-TEST-2026@0.0.1')
        ->withFacts((new TaxFactRepository())->snapshotsFor($subject->id, [$fact->id]));

    $result = makeTaxAssessor()->assess($context);

    expect($result->lines)->toHaveCount(1);

    $line = $result->lines[0];
    expect($line->taxCode)->toBe('SYNTHETIC_TAX')
        ->and($line->taxableBase->minorUnits)->toBe(100000)
        ->and($line->tax->minorUnits)->toBe(10000) // 10% of 1000.00 RON
        ->and($line->rate?->toString())->toBe('0.10');

    // The trace alone re-derives the result: base * rate, both recorded as steps.
    $trace = $line->trace->toArray();
    expect($trace['steps'][0]['result'])->toBe('1000.00 RON')
        ->and($trace['steps'][1]['result'])->toBe('100.00 RON')
        ->and($trace['result'])->toBe($line->tax->toString());

    expect($result->totals['SYNTHETIC_TAX']->minorUnits)->toBe(10000)
        ->and($result->obligations)->toHaveCount(1)
        ->and($result->obligations[0]->amountDue->minorUnits)->toBe(10000);
});

it('produces an identical result from an identical context — reproducibility', function (): void {
    [$subject] = seedSyntheticAssessmentFixture(ApprovalStatus::CANDIDATE);

    $fact = TaxFact::factory()->create(['tax_subject_id' => $subject->id, 'ron_amount_minor' => 250000]);

    $buildContext = fn () => TaxContext::forSubject($subject->id)
        ->fiscalYear(2026)
        ->asOf(new DateTimeImmutable('2026-06-01'))
        ->forRules(['RO.TEST.SYNTHETIC_PERCENTAGE_OF_BASE'])
        ->withRulesetId('RO-TEST-2026@0.0.1')
        ->withFacts((new TaxFactRepository())->snapshotsFor($subject->id, [$fact->id]));

    $assessor = makeTaxAssessor();
    $first = $assessor->assess($buildContext());
    $second = $assessor->assess($buildContext());

    expect($first->inputSnapshotHash)->toBe($second->inputSnapshotHash)
        ->and($first->lines[0]->tax->minorUnits)->toBe($second->lines[0]->tax->minorUnits)
        ->and($first->lines[0]->trace->toArray())->toBe($second->lines[0]->trace->toArray());
});

it('sums multiple facts into one taxable base', function (): void {
    [$subject] = seedSyntheticAssessmentFixture(ApprovalStatus::CANDIDATE);

    $factA = TaxFact::factory()->create(['tax_subject_id' => $subject->id, 'ron_amount_minor' => 100000]);
    $factB = TaxFact::factory()->create(['tax_subject_id' => $subject->id, 'ron_amount_minor' => 50000]);

    $context = TaxContext::forSubject($subject->id)
        ->fiscalYear(2026)
        ->asOf(new DateTimeImmutable('2026-06-01'))
        ->forRules(['RO.TEST.SYNTHETIC_PERCENTAGE_OF_BASE'])
        ->withRulesetId('RO-TEST-2026@0.0.1')
        ->withFacts((new TaxFactRepository())->snapshotsFor($subject->id, [$factA->id, $factB->id]));

    $result = makeTaxAssessor()->assess($context);

    expect($result->lines[0]->taxableBase->minorUnits)->toBe(150000)
        ->and($result->lines[0]->tax->minorUnits)->toBe(15000);
});

it('cannot produce a FINAL assessment from a CANDIDATE ruleset', function (): void {
    [$subject] = seedSyntheticAssessmentFixture(ApprovalStatus::CANDIDATE);

    $context = TaxContext::forSubject($subject->id)
        ->fiscalYear(2026)
        ->asOf(new DateTimeImmutable('2026-06-01'))
        ->forRules(['RO.TEST.SYNTHETIC_PERCENTAGE_OF_BASE'])
        ->withRulesetId('RO-TEST-2026@0.0.1')
        ->withFacts([])
        ->final();

    expect(fn () => makeTaxAssessor()->assess($context))
        ->toThrow(CandidateNotAllowedInFinalAssessmentException::class);
});

it('produces a FINAL assessment from an APPROVED ruleset', function (): void {
    [$subject] = seedSyntheticAssessmentFixture(ApprovalStatus::APPROVED);

    $fact = TaxFact::factory()->create(['tax_subject_id' => $subject->id, 'ron_amount_minor' => 100000]);

    $context = TaxContext::forSubject($subject->id)
        ->fiscalYear(2026)
        ->asOf(new DateTimeImmutable('2026-06-01'))
        ->forRules(['RO.TEST.SYNTHETIC_PERCENTAGE_OF_BASE'])
        ->withRulesetId('RO-TEST-2026@0.0.1')
        ->withFacts((new TaxFactRepository())->snapshotsFor($subject->id, [$fact->id]))
        ->final();

    $result = makeTaxAssessor()->assess($context);

    expect($result->status)->toBe(AssessmentStatus::FINAL)
        ->and($result->lines[0]->tax->minorUnits)->toBe(10000);
});
