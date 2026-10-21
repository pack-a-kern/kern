<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Declarations\ValidateDeclarationTest;

use Illuminate\Support\Facades\Artisan;
use Kern\TaxRomania\Declarations\Actions\ValidateDeclaration;
use Kern\TaxRomania\Declarations\DataTransferObjects\ValidationIssue;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Exceptions\DeclarationValidationFailedException;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use RuntimeException;

/**
 * The safety property this whole module exists to enforce (`docs/AGENTS.md` §9): a declaration
 * built on a `DRAFT` `tax_assessments` row (one that may have used a `CANDIDATE` — not yet
 * human-approved — tax rule or fiscal parameter version, `docs/handoff/020-tax-engine.md`) must
 * never become `VALID`/fileable. See the `'rejects a declaration whose assessment is not FINAL'`
 * test below.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * @return list<string>
 */
function issueCodes(DeclarationValidationFailedException $exception): array
{
    return array_map(static fn (ValidationIssue $issue): string => $issue->code, $exception->issues);
}

/**
 * Runs `ValidateDeclaration`, asserts it threw `DeclarationValidationFailedException` carrying
 * `$expectedIssueCode`, and returns the caught exception for any further assertions.
 */
function assertValidationFailsWith(Declaration $declaration, string $expectedIssueCode): DeclarationValidationFailedException
{
    try {
        app(ValidateDeclaration::class)($declaration);
    } catch (DeclarationValidationFailedException $exception) {
        expect(issueCodes($exception))->toContain($expectedIssueCode);

        return $exception;
    }

    throw new RuntimeException('Expected DeclarationValidationFailedException to be thrown.');
}

it('accepts a well-formed declaration, freezes the snapshot, and moves it to VALID', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 12345, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => 2026, 'tax_year_to' => 2026]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    app(ValidateDeclaration::class)($declaration);

    $declaration->refresh();

    expect($declaration->status)->toBe(DeclarationStatus::VALID)
        ->and($declaration->snapshot_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($declaration->snapshot_json)
        ->toMatchArray([
            'assessment_id' => $assessment->id,
            'assessment_ruleset_id' => $assessment->ruleset_id,
            'form_version_id' => $formVersion->id,
            'artifact_format' => 'PLACEHOLDER_NOT_ANAF_FORMAT',
        ]);
});

it('rejects a declaration whose assessment is not FINAL, and leaves it in DRAFT', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->create([ // default status: DRAFT
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => 2026, 'tax_year_to' => 2026]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    assertValidationFailsWith($declaration, 'ASSESSMENT_NOT_FINAL');

    $declaration->refresh();
    expect($declaration->status)->toBe(DeclarationStatus::DRAFT)
        ->and($declaration->snapshot_hash)->toBeNull();
});

it('rejects a declaration whose assessment belongs to a different tax subject', function (): void {
    $subject = TaxSubject::factory()->create();
    $otherSubject = TaxSubject::factory()->create();

    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $otherSubject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => 2026, 'tax_year_to' => 2026]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    assertValidationFailsWith($declaration, 'ASSESSMENT_SUBJECT_MISMATCH');
});

it('rejects a declaration whose fiscal year does not match its assessment', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2025,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => 2020, 'tax_year_to' => 2026]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    assertValidationFailsWith($declaration, 'ASSESSMENT_FISCAL_YEAR_MISMATCH');
});

it('rejects a declaration built on a non-ACTIVE form version', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->draft()->create(['tax_year_from' => 2026, 'tax_year_to' => 2026]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    assertValidationFailsWith($declaration, 'FORM_VERSION_NOT_ACTIVE');
});

it('rejects a declaration whose form version does not apply to the fiscal year', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => 2020, 'tax_year_to' => 2022]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    assertValidationFailsWith($declaration, 'FORM_VERSION_FISCAL_YEAR_NOT_APPLICABLE');
});

it('rejects a declaration whose assessment has no computed totals', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => [],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => 2026, 'tax_year_to' => 2026]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    assertValidationFailsWith($declaration, 'EMPTY_ASSESSMENT_TOTALS');
});

it('rejects a declaration whose form version carries a malformed schema', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create([
        'tax_year_from' => 2026,
        'tax_year_to' => 2026,
        'schema_json' => ['sections' => [['order' => 1, 'label' => 'no code here', 'fields' => []]]],
    ]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    assertValidationFailsWith($declaration, 'FORM_VERSION_SCHEMA_MALFORMED');
});
