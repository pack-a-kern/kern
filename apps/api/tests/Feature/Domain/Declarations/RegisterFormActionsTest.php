<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Declarations\RegisterFormActionsTest;

use App\Domain\Declarations\Actions\CreateDeclarationDraft;
use App\Domain\Declarations\Actions\RegisterFormDefinition;
use App\Domain\Declarations\Actions\RegisterFormVersion;
use App\Domain\Declarations\DataTransferObjects\CreateDeclarationDraftInput;
use App\Domain\Declarations\DataTransferObjects\RegisterFormDefinitionInput;
use App\Domain\Declarations\DataTransferObjects\RegisterFormVersionInput;
use App\Domain\Declarations\Enums\DeclarationKind;
use App\Domain\Declarations\Enums\DeclarationStatus;
use App\Domain\Declarations\Enums\FormVersionStatus;
use App\Domain\Declarations\Exceptions\InvalidFormSchemaException;
use App\Domain\Declarations\Exceptions\InvalidFormVersionDateRangeException;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\TaxEngine\Models\TaxAssessment;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('registers a form definition and an ACTIVE form version applicable to a fiscal year range', function (): void {
    $definition = app(RegisterFormDefinition::class)(new RegisterFormDefinitionInput(
        code: 'SYNTHETIC_TEST_FORM',
        issuer: 'SYNTHETIC_TEST_ISSUER',
    ));

    $version = app(RegisterFormVersion::class)(new RegisterFormVersionInput(
        formDefinitionId: $definition->id,
        versionCode: '2026.1',
        applicableFrom: '2026-01-01',
        taxYearFrom: 2026,
        taxYearTo: 2026,
        status: FormVersionStatus::ACTIVE,
    ));

    expect($version->form_definition_id)->toBe($definition->id)
        ->and($version->status)->toBe(FormVersionStatus::ACTIVE)
        ->and($version->appliesToFiscalYear(2026))->toBeTrue()
        ->and($version->appliesToFiscalYear(2027))->toBeFalse();
});

it('rejects a form version whose applicable_until is not after applicable_from', function (): void {
    $definition = app(RegisterFormDefinition::class)(new RegisterFormDefinitionInput('SYNTHETIC_TEST_FORM', 'SYNTHETIC_TEST_ISSUER'));

    expect(fn () => app(RegisterFormVersion::class)(new RegisterFormVersionInput(
        formDefinitionId: $definition->id,
        versionCode: '2026.1',
        applicableFrom: '2026-06-01',
        applicableUntil: '2026-01-01',
    )))->toThrow(InvalidFormVersionDateRangeException::class);
});

it('rejects a form version whose tax_year_to precedes tax_year_from', function (): void {
    $definition = app(RegisterFormDefinition::class)(new RegisterFormDefinitionInput('SYNTHETIC_TEST_FORM', 'SYNTHETIC_TEST_ISSUER'));

    expect(fn () => app(RegisterFormVersion::class)(new RegisterFormVersionInput(
        formDefinitionId: $definition->id,
        versionCode: '2026.1',
        applicableFrom: '2026-01-01',
        taxYearFrom: 2026,
        taxYearTo: 2020,
    )))->toThrow(InvalidFormVersionDateRangeException::class);
});

it('rejects a form version whose schema_json is malformed', function (): void {
    $definition = app(RegisterFormDefinition::class)(new RegisterFormDefinitionInput('SYNTHETIC_TEST_FORM', 'SYNTHETIC_TEST_ISSUER'));

    expect(fn () => app(RegisterFormVersion::class)(new RegisterFormVersionInput(
        formDefinitionId: $definition->id,
        versionCode: '2026.1',
        applicableFrom: '2026-01-01',
        schemaJson: ['not_sections' => []],
    )))->toThrow(InvalidFormSchemaException::class);
});

it('accepts a form version with a well-formed synthetic schema', function (): void {
    $definition = app(RegisterFormDefinition::class)(new RegisterFormDefinitionInput('SYNTHETIC_TEST_FORM', 'SYNTHETIC_TEST_ISSUER'));

    $version = app(RegisterFormVersion::class)(new RegisterFormVersionInput(
        formDefinitionId: $definition->id,
        versionCode: '2026.1',
        applicableFrom: '2026-01-01',
        schemaJson: [
            'sections' => [
                ['code' => 'SYNTHETIC_SECTION', 'order' => 1, 'label' => 'x', 'fields' => [
                    ['code' => 'SYNTHETIC_FIELD', 'order' => 1, 'label' => 'x', 'data_type' => 'DECIMAL'],
                ]],
            ],
        ],
    ));

    expect($version->schema_json)->not->toBeNull();
});

it('opens a new ORIGINAL declaration draft', function (): void {
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create(['tax_subject_id' => $subject->id, 'fiscal_year' => 2026]);

    $definition = app(RegisterFormDefinition::class)(new RegisterFormDefinitionInput('SYNTHETIC_TEST_FORM', 'SYNTHETIC_TEST_ISSUER'));
    $formVersion = app(RegisterFormVersion::class)(new RegisterFormVersionInput(
        formDefinitionId: $definition->id,
        versionCode: '2026.1',
        applicableFrom: '2026-01-01',
        taxYearFrom: 2026,
        taxYearTo: 2026,
        status: FormVersionStatus::ACTIVE,
    ));

    $declaration = app(CreateDeclarationDraft::class)(new CreateDeclarationDraftInput(
        taxSubjectId: $subject->id,
        formVersionId: $formVersion->id,
        fiscalYear: 2026,
        assessmentId: $assessment->id,
    ));

    expect($declaration->kind)->toBe(DeclarationKind::ORIGINAL)
        ->and($declaration->status)->toBe(DeclarationStatus::DRAFT)
        ->and($declaration->tax_subject_id)->toBe($subject->id)
        ->and($declaration->assessment_id)->toBe($assessment->id);
});
