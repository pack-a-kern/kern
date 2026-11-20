<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\RegisterPfaSubjectTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Audit\Services\ChainVerifier;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Actions\RegisterPfaSubject;
use Kern\TaxRomania\Subjects\Actions\ResetTaxSubject;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfaSubjectInput;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Exceptions\DuplicateRegistrationNumberException;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;

/**
 * The PFA mirror of `tests/Feature/TaxRomania/Subjects/RegisterPfSubjectTest.php` — Milestone 12's
 * extension proof (`docs/handoff/034-pfa-extension.md`, `BLUEPRINT.md` §38.B). Every assertion here
 * is the identical shape that file already proves for PF, run instead against
 * `Kern\TaxRomania\Subjects\Actions\RegisterPfaSubject` — demonstrating the same guarantees
 * (membership creation, a verifiable audit event, duplicate-identifier rejection) hold for a
 * second subject type with no shared-mechanism change.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function pfaRegistrationInput(string $userId, string $registrationNumber = 'J40/1234/2020'): RegisterPfaSubjectInput
{
    return new RegisterPfaSubjectInput(
        registeringUserId: $userId,
        registeringUserRoles: ['ADMIN'],
        displayName: 'Ion Popescu PFA',
        tradeName: 'Popescu Ion PFA',
        registrationNumber: $registrationNumber,
        caenCode: '6201',
        countyCode: 'B',
        locality: 'București',
        address: 'Str. Exemplu nr. 1',
    );
}

it('creates a PFA subject with an OWNER membership for the registering user', function (): void {
    $user = User::factory()->create();

    $subject = app(RegisterPfaSubject::class)(pfaRegistrationInput($user->id));

    expect($subject->type)->toBe(SubjectType::PFA);

    $membership = SubjectMembership::query()
        ->where('subject_id', $subject->id)
        ->where('user_id', $user->id)
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership?->role)->toBe(MembershipRole::OWNER)
        ->and($membership?->revoked_at)->toBeNull();
});

it('records a verifiable audit event for the registration', function (): void {
    $user = User::factory()->create();

    $subject = app(RegisterPfaSubject::class)(pfaRegistrationInput($user->id));

    $event = DB::table('audit_events')->where('subject_id', $subject->id)->first();

    expect($event)->not->toBeNull()
        ->and($event?->event_type)->toBe('TaxSubjectRegistered')
        ->and($event?->actor_id)->toBe($user->id);

    $verification = app(ChainVerifier::class)->verify($subject->id);
    expect($verification->ok)->toBeTrue();
});

it('rejects a second registration with the same registration number under a different subject', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    app(RegisterPfaSubject::class)(pfaRegistrationInput($firstUser->id, 'J40/1234/2020'));

    expect(fn () => app(RegisterPfaSubject::class)(pfaRegistrationInput($secondUser->id, '  J40/1234/2020  ')))
        ->toThrow(DuplicateRegistrationNumberException::class);
});

it('allows two different subjects to register with genuinely different registration numbers', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $first = app(RegisterPfaSubject::class)(pfaRegistrationInput($firstUser->id, 'J40/1234/2020'));
    $second = app(RegisterPfaSubject::class)(pfaRegistrationInput($secondUser->id, 'J40/5678/2020'));

    expect($first->id)->not->toBe($second->id);
});

it('reconnects a reset PFA to whoever registers its number again, the same row cleaned', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $subject = app(RegisterPfaSubject::class)(pfaRegistrationInput($first->id, 'J40/1234/2020'));
    $originalId = $subject->id;

    app(TenantContext::class)->authorizeAndActivate($first->id, $originalId);
    app(ResetTaxSubject::class)($subject, $first->id);

    $reattached = app(RegisterPfaSubject::class)(pfaRegistrationInput($second->id, '  J40/1234/2020  '));

    expect($reattached->id)->toBe($originalId)
        ->and($reattached->status)->toBe(SubjectStatus::ACTIVE);

    $ownedBySecond = SubjectMembership::query()->where('subject_id', $originalId)
        ->where('user_id', $second->id)->whereNull('revoked_at')->exists();
    $ownedByFirst = SubjectMembership::query()->where('subject_id', $originalId)
        ->where('user_id', $first->id)->whereNull('revoked_at')->exists();

    expect($ownedBySecond)->toBeTrue()->and($ownedByFirst)->toBeFalse();
    expect(DB::table('tax_subjects')->where('id', $originalId)->value('fiscal_identifier_kind'))
        ->toBe(FiscalIdentifierKind::CUI->value);
});

it('populates the canonical fiscal_identifier column on tax_subjects, tagged CUI, for a PFA registration', function (): void {
    $user = User::factory()->create();

    $subject = app(RegisterPfaSubject::class)(pfaRegistrationInput($user->id, 'J40/8888/2020'));

    /**
     * @var object{
     *     fiscal_identifier_ciphertext: string|null, fiscal_identifier_blind_index: string|null,
     *     fiscal_identifier_kind: string|null,
     * }|null $raw
     */
    $raw = DB::table('tax_subjects')->where('id', $subject->id)->first();

    expect($raw)->not->toBeNull()
        ->and($raw?->fiscal_identifier_ciphertext)->not->toBeNull()
        ->and($raw?->fiscal_identifier_blind_index)->not->toBeNull()
        ->and($raw?->fiscal_identifier_kind)->toBe(FiscalIdentifierKind::CUI->value);

    // ADR 0011: decrypts back to the registration number, exactly like RegisterPfSubject does for
    // a CNP — the column is genuinely shared, not PF-only in practice.
    expect($subject->fresh()?->fiscal_identifier_ciphertext)->toBe('J40/8888/2020')
        ->and($subject->fresh()?->fiscal_identifier_kind)->toBe(FiscalIdentifierKind::CUI);
});
