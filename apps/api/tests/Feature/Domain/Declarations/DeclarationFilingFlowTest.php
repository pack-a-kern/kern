<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Declarations\DeclarationFilingFlowTest;

use App\Domain\Declarations\Actions\CreateRectificativeDeclaration;
use App\Domain\Declarations\Actions\RecordDeclarationSubmissionResponse;
use App\Domain\Declarations\Actions\SubmitDeclaration;
use App\Domain\Declarations\Actions\ValidateDeclaration;
use App\Domain\Declarations\DataTransferObjects\CreateRectificativeDeclarationInput;
use App\Domain\Declarations\DataTransferObjects\RecordDeclarationSubmissionResponseInput;
use App\Domain\Declarations\DataTransferObjects\SubmitDeclarationInput;
use App\Domain\Declarations\Enums\DeclarationKind;
use App\Domain\Declarations\Enums\DeclarationStatus;
use App\Domain\Declarations\Enums\SubmissionTransport;
use App\Domain\Declarations\Exceptions\DeclarationNotSubmittedException;
use App\Domain\Declarations\Exceptions\DeclarationNotValidException;
use App\Domain\Declarations\Exceptions\InvalidSubmissionOutcomeException;
use App\Domain\Declarations\Exceptions\RectificativeSourceNotFiledException;
use App\Domain\Declarations\Models\Declaration;
use App\Domain\Declarations\Models\FormVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\TaxEngine\Models\TaxAssessment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;

/**
 * The end-to-end path: `CreateDeclarationDraft` (not re-tested here, see
 * `ValidateDeclarationTest.php`) `-> ValidateDeclaration -> SubmitDeclaration ->
 * RecordDeclarationSubmissionResponse -> CreateRectificativeDeclaration` — `BLUEPRINT.md` §12.1/§12.3
 * end to end, plus every documented guard along the way.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * @return array{0: User, 1: TaxSubject}
 */
function filingOwnerAndSubject(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    return [$user, $subject];
}

function validDeclarationFor(TaxSubject $subject, int $fiscalYear = 2026): Declaration
{
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => $fiscalYear,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 5000, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => $fiscalYear, 'tax_year_to' => $fiscalYear]);

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => $fiscalYear,
        'assessment_id' => $assessment->id,
    ]);

    app(ValidateDeclaration::class)($declaration);

    return $declaration->refresh();
}

it('submits a VALID declaration, locks it, and later records an ACCEPTED outcome', function (): void {
    [, $subject] = filingOwnerAndSubject();
    $declaration = validDeclarationFor($subject);

    expect($declaration->status)->toBe(DeclarationStatus::VALID);

    $submission = app(SubmitDeclaration::class)(new SubmitDeclarationInput(
        declarationId: $declaration->id,
        transport: SubmissionTransport::MANUAL,
        submittedAt: '2026-06-01T10:00:00+00:00',
        externalReference: 'SYNTHETIC-REF-1',
    ));

    $declaration->refresh();
    expect($declaration->status)->toBe(DeclarationStatus::SUBMITTED)
        ->and($declaration->locked_at)->not->toBeNull();

    app(RecordDeclarationSubmissionResponse::class)(new RecordDeclarationSubmissionResponseInput(
        submissionId: $submission->id,
        outcome: DeclarationStatus::ACCEPTED,
        responsePayload: 'SYNTHETIC ANAF RESPONSE: accepted.',
    ));

    expect($declaration->refresh()->status)->toBe(DeclarationStatus::ACCEPTED);
});

it('refuses to submit a declaration that is not VALID', function (): void {
    [, $subject] = filingOwnerAndSubject();

    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 100, 'currency' => 'RON']],
    ]);
    $formVersion = FormVersion::factory()->create(['tax_year_from' => 2026, 'tax_year_to' => 2026]);

    $declaration = Declaration::create([ // still DRAFT — never validated
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    expect(fn () => app(SubmitDeclaration::class)(new SubmitDeclarationInput(
        declarationId: $declaration->id,
        transport: SubmissionTransport::MANUAL,
        submittedAt: '2026-06-01T10:00:00+00:00',
    )))->toThrow(DeclarationNotValidException::class);
});

it('refuses to record a response for a declaration that is not SUBMITTED', function (): void {
    [, $subject] = filingOwnerAndSubject();
    $declaration = validDeclarationFor($subject);

    $submission = app(SubmitDeclaration::class)(new SubmitDeclarationInput(
        declarationId: $declaration->id,
        transport: SubmissionTransport::MANUAL,
        submittedAt: '2026-06-01T10:00:00+00:00',
    ));

    app(RecordDeclarationSubmissionResponse::class)(new RecordDeclarationSubmissionResponseInput(
        submissionId: $submission->id,
        outcome: DeclarationStatus::ACCEPTED,
    ));

    // Second attempt: the declaration is now ACCEPTED, not SUBMITTED.
    expect(fn () => app(RecordDeclarationSubmissionResponse::class)(new RecordDeclarationSubmissionResponseInput(
        submissionId: $submission->id,
        outcome: DeclarationStatus::REJECTED,
    )))->toThrow(DeclarationNotSubmittedException::class);
});

it('refuses a submission outcome that is not ACCEPTED or REJECTED', function (): void {
    [, $subject] = filingOwnerAndSubject();
    $declaration = validDeclarationFor($subject);

    $submission = app(SubmitDeclaration::class)(new SubmitDeclarationInput(
        declarationId: $declaration->id,
        transport: SubmissionTransport::MANUAL,
        submittedAt: '2026-06-01T10:00:00+00:00',
    ));

    expect(fn () => app(RecordDeclarationSubmissionResponse::class)(new RecordDeclarationSubmissionResponseInput(
        submissionId: $submission->id,
        outcome: DeclarationStatus::DRAFT,
    )))->toThrow(InvalidSubmissionOutcomeException::class);
});

it('creates a RECTIFICATIVE declaration referencing the filed original, without mutating it', function (): void {
    [, $subject] = filingOwnerAndSubject();
    $declaration = validDeclarationFor($subject);

    app(SubmitDeclaration::class)(new SubmitDeclarationInput(
        declarationId: $declaration->id,
        transport: SubmissionTransport::MANUAL,
        submittedAt: '2026-06-01T10:00:00+00:00',
    ));
    $originalSnapshotHash = $declaration->refresh()->snapshot_hash;

    $correctedAssessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 9999, 'currency' => 'RON']],
    ]);

    $rectificative = app(CreateRectificativeDeclaration::class)(new CreateRectificativeDeclarationInput(
        originalDeclarationId: $declaration->id,
        assessmentId: $correctedAssessment->id,
    ));

    expect($rectificative->kind)->toBe(DeclarationKind::RECTIFICATIVE)
        ->and($rectificative->status)->toBe(DeclarationStatus::DRAFT)
        ->and($rectificative->supersedes_declaration_id)->toBe($declaration->id)
        ->and($rectificative->tax_subject_id)->toBe($declaration->tax_subject_id)
        ->and($rectificative->fiscal_year)->toBe($declaration->fiscal_year)
        ->and($rectificative->form_version_id)->toBe($declaration->form_version_id);

    // The original is untouched.
    expect($declaration->refresh()->snapshot_hash)->toBe($originalSnapshotHash)
        ->and($declaration->status)->toBe(DeclarationStatus::SUBMITTED);
});

it('refuses to create a RECTIFICATIVE from a declaration that was never filed', function (): void {
    [, $subject] = filingOwnerAndSubject();
    $declaration = validDeclarationFor($subject); // VALID, never submitted

    $correctedAssessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
        'totals_json' => ['SYNTHETIC_TAX' => ['minor_units' => 1, 'currency' => 'RON']],
    ]);

    expect(fn () => app(CreateRectificativeDeclaration::class)(new CreateRectificativeDeclarationInput(
        originalDeclarationId: $declaration->id,
        assessmentId: $correctedAssessment->id,
    )))->toThrow(RectificativeSourceNotFiledException::class);
});
