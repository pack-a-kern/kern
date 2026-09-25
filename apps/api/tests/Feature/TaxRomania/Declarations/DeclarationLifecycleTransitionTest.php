<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Declarations\DeclarationLifecycleTransitionTest;

use Illuminate\Support\Facades\Artisan;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Exceptions\InvalidDeclarationTransitionException;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Declarations\Services\DeclarationLifecycle;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use RuntimeException;

/**
 * `Kern\TaxRomania\Declarations\Enums\DeclarationStatus`'s own docblock is the specification this
 * proves: every allowed transition succeeds, and — just as importantly — every transition NOT in
 * that matrix is rejected, never silently accepted or coerced.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function freshDraftDeclaration(): Declaration
{
    $subject = TaxSubject::factory()->create();
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
    ]);
    $formVersion = FormVersion::factory()->create();

    return Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);
}

it('allows every transition named in DeclarationStatus\'s own docblock', function (): void {
    $lifecycle = new DeclarationLifecycle();
    $declaration = freshDraftDeclaration();

    expect($declaration->status)->toBe(DeclarationStatus::DRAFT);

    $lifecycle->transition($declaration, DeclarationStatus::VALIDATING);
    expect($declaration->refresh()->status)->toBe(DeclarationStatus::VALIDATING);

    $lifecycle->transition($declaration, DeclarationStatus::DRAFT);
    expect($declaration->refresh()->status)->toBe(DeclarationStatus::DRAFT);

    $lifecycle->transition($declaration, DeclarationStatus::VALIDATING);
    $lifecycle->transition($declaration, DeclarationStatus::VALID);
    expect($declaration->refresh()->status)->toBe(DeclarationStatus::VALID);

    $lifecycle->transition($declaration, DeclarationStatus::SUBMITTED, ['locked_at' => now('UTC')]);
    expect($declaration->refresh()->status)->toBe(DeclarationStatus::SUBMITTED);

    $lifecycle->transition($declaration, DeclarationStatus::ACCEPTED);
    expect($declaration->refresh()->status)->toBe(DeclarationStatus::ACCEPTED);
});

it('allows SUBMITTED -> REJECTED on a separate declaration', function (): void {
    $lifecycle = new DeclarationLifecycle();
    $declaration = freshDraftDeclaration();

    $lifecycle->transition($declaration, DeclarationStatus::VALIDATING);
    $lifecycle->transition($declaration, DeclarationStatus::VALID);
    $lifecycle->transition($declaration, DeclarationStatus::SUBMITTED, ['locked_at' => now('UTC')]);
    $lifecycle->transition($declaration, DeclarationStatus::REJECTED);

    expect($declaration->refresh()->status)->toBe(DeclarationStatus::REJECTED);
});

dataset('invalid transitions', [
    'DRAFT -> VALID (skips VALIDATING)' => [DeclarationStatus::DRAFT, DeclarationStatus::VALID],
    'DRAFT -> SUBMITTED' => [DeclarationStatus::DRAFT, DeclarationStatus::SUBMITTED],
    'DRAFT -> ACCEPTED' => [DeclarationStatus::DRAFT, DeclarationStatus::ACCEPTED],
    'VALIDATING -> SUBMITTED' => [DeclarationStatus::VALIDATING, DeclarationStatus::SUBMITTED],
    'VALID -> DRAFT' => [DeclarationStatus::VALID, DeclarationStatus::DRAFT],
    'VALID -> ACCEPTED (skips SUBMITTED)' => [DeclarationStatus::VALID, DeclarationStatus::ACCEPTED],
    'SUBMITTED -> DRAFT' => [DeclarationStatus::SUBMITTED, DeclarationStatus::DRAFT],
    'SUBMITTED -> VALID' => [DeclarationStatus::SUBMITTED, DeclarationStatus::VALID],
]);

it('rejects every transition not named in the matrix', function (DeclarationStatus $from, DeclarationStatus $to): void {
    $lifecycle = new DeclarationLifecycle();
    $declaration = freshDraftDeclaration();

    // Drive the declaration to $from using only legal moves, without ever locking it early.
    $path = match ($from) {
        DeclarationStatus::DRAFT => [],
        DeclarationStatus::VALIDATING => [DeclarationStatus::VALIDATING],
        DeclarationStatus::VALID => [DeclarationStatus::VALIDATING, DeclarationStatus::VALID],
        DeclarationStatus::SUBMITTED => [DeclarationStatus::VALIDATING, DeclarationStatus::VALID, DeclarationStatus::SUBMITTED],
        default => throw new RuntimeException('Unsupported starting status in this dataset.'),
    };

    foreach ($path as $step) {
        $lifecycle->transition(
            $declaration,
            $step,
            $step === DeclarationStatus::SUBMITTED ? ['locked_at' => now('UTC')] : [],
        );
    }

    expect($declaration->refresh()->status)->toBe($from);

    expect(fn () => $lifecycle->transition($declaration, $to))
        ->toThrow(InvalidDeclarationTransitionException::class);
})->with('invalid transitions');
