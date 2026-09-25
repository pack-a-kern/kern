<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Declarations\DeclarationImmutabilityTest;

use Illuminate\Support\Facades\Artisan;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Exceptions\DeclarationIsImmutableException;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Declarations\Services\DeclarationLifecycle;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * `docs/AGENTS.md` rule 18 — "retroactively change a submitted declaration" — proven directly
 * against `Kern\TaxRomania\Declarations\Models\Declaration`'s own `saving` guard, bypassing
 * `Kern\TaxRomania\Declarations\Services\DeclarationLifecycle` entirely: this is the model-level
 * defense-in-depth layer, not the action-layer one already proven in
 * `DeclarationLifecycleTransitionTest.php`.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function submittedDeclaration(): Declaration
{
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
    ]);
    $formVersion = FormVersion::factory()->create();

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    $lifecycle = new DeclarationLifecycle();
    $lifecycle->transition($declaration, DeclarationStatus::VALIDATING);
    $lifecycle->transition($declaration, DeclarationStatus::VALID);
    $lifecycle->transition($declaration, DeclarationStatus::SUBMITTED, ['locked_at' => now('UTC')]);

    return $declaration->refresh();
}

it('refuses to save any change to a submitted declaration\'s frozen fields', function (): void {
    $declaration = submittedDeclaration();
    $otherFormVersion = FormVersion::factory()->create();

    $declaration->form_version_id = $otherFormVersion->id;

    expect(fn () => $declaration->save())->toThrow(DeclarationIsImmutableException::class);
});

it('refuses to save an attempt to move a submitted declaration back to DRAFT', function (): void {
    $declaration = submittedDeclaration();

    $declaration->status = DeclarationStatus::DRAFT;

    expect(fn () => $declaration->save())->toThrow(DeclarationIsImmutableException::class);
});

it('refuses to save status + another dirty column together, even toward ACCEPTED', function (): void {
    $declaration = submittedDeclaration();

    $declaration->status = DeclarationStatus::ACCEPTED;
    $declaration->fiscal_year = 2027;

    expect(fn () => $declaration->save())->toThrow(DeclarationIsImmutableException::class);
});

it('still allows the sole sanctioned SUBMITTED -> ACCEPTED status flip', function (): void {
    $declaration = submittedDeclaration();

    $declaration->status = DeclarationStatus::ACCEPTED;
    $declaration->save();

    expect($declaration->refresh()->status)->toBe(DeclarationStatus::ACCEPTED);
});

it('refuses to save any further change once ACCEPTED, including a second status flip', function (): void {
    $declaration = submittedDeclaration();
    $declaration->status = DeclarationStatus::ACCEPTED;
    $declaration->save();

    $declaration->status = DeclarationStatus::REJECTED;

    expect(fn () => $declaration->save())->toThrow(DeclarationIsImmutableException::class);
});
