<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Declarations\DeclarationSnapshotBuilderTest;

use Illuminate\Support\Facades\Artisan;
use Kern\TaxRomania\Declarations\DataTransferObjects\AssessmentSnapshot;
use Kern\TaxRomania\Declarations\DataTransferObjects\GeneratedArtifact;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Declarations\Services\DeclarationSnapshotBuilder;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * `BLUEPRINT.md` §39 reproducibility, applied to `declarations.snapshot_hash`: the same inputs must
 * always hash identically, regardless of PHP array insertion order — the same discipline
 * `Kern\TaxRomania\TaxEngine\Services\TaxAssessor::sorted()` already applies to `input_snapshot_hash`.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('produces an identical hash for the same content regardless of totals key order', function (): void {
    $subject = TaxSubject::factory()->create();
    $formVersion = FormVersion::factory()->create();

    // The real `tax_assessments` row backing the FK is unrelated to the synthetic
    // `AssessmentSnapshot` DTOs constructed below — this test exercises the builder's own
    // hashing discipline, not what the DB row actually contains.
    $backingAssessment = TaxAssessment::factory()->create(['tax_subject_id' => $subject->id]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $backingAssessment->id,
    ]);

    $builder = new DeclarationSnapshotBuilder();
    $artifact = new GeneratedArtifact('content', 'application/json', hash('sha256', 'content'), 'PLACEHOLDER_NOT_ANAF_FORMAT');

    $assessmentA = new AssessmentSnapshot(
        id: 'assessment-1',
        taxSubjectId: $subject->id,
        fiscalYear: 2026,
        asOf: '2026-05-01',
        rulesetId: 'RULESET-1',
        inputSnapshotHash: 'deadbeef',
        status: 'FINAL',
        totals: ['B_TAX' => ['minor_units' => 200, 'currency' => 'RON'], 'A_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    );

    $assessmentB = new AssessmentSnapshot(
        id: 'assessment-1',
        taxSubjectId: $subject->id,
        fiscalYear: 2026,
        asOf: '2026-05-01',
        rulesetId: 'RULESET-1',
        inputSnapshotHash: 'deadbeef',
        status: 'FINAL',
        totals: ['A_TAX' => ['minor_units' => 100, 'currency' => 'RON'], 'B_TAX' => ['minor_units' => 200, 'currency' => 'RON']],
    );

    $hashA = $builder->finalize($builder->buildBase($declaration, $assessmentA, $formVersion), $artifact)['hash'];
    $hashB = $builder->finalize($builder->buildBase($declaration, $assessmentB, $formVersion), $artifact)['hash'];

    expect($hashA)->toBe($hashB);
});

it('produces a different hash when the assessment totals differ', function (): void {
    $subject = TaxSubject::factory()->create();
    $formVersion = FormVersion::factory()->create();

    // The real `tax_assessments` row backing the FK is unrelated to the synthetic
    // `AssessmentSnapshot` DTOs constructed below — this test exercises the builder's own
    // hashing discipline, not what the DB row actually contains.
    $backingAssessment = TaxAssessment::factory()->create(['tax_subject_id' => $subject->id]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $backingAssessment->id,
    ]);

    $builder = new DeclarationSnapshotBuilder();
    $artifact = new GeneratedArtifact('content', 'application/json', hash('sha256', 'content'), 'PLACEHOLDER_NOT_ANAF_FORMAT');

    $base = fn (int $amount): AssessmentSnapshot => new AssessmentSnapshot(
        id: 'assessment-1',
        taxSubjectId: $subject->id,
        fiscalYear: 2026,
        asOf: '2026-05-01',
        rulesetId: 'RULESET-1',
        inputSnapshotHash: 'deadbeef',
        status: 'FINAL',
        totals: ['A_TAX' => ['minor_units' => $amount, 'currency' => 'RON']],
    );

    $hashA = $builder->finalize($builder->buildBase($declaration, $base(100), $formVersion), $artifact)['hash'];
    $hashB = $builder->finalize($builder->buildBase($declaration, $base(200), $formVersion), $artifact)['hash'];

    expect($hashA)->not->toBe($hashB);
});
