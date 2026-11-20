<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\RegisterPfSubjectTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Audit\Services\ChainVerifier;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Actions\RegisterPfSubject;
use Kern\TaxRomania\Subjects\Actions\ResetTaxSubject;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Exceptions\DuplicateCnpException;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Queries\FindSubjectByCnp;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function registrationInput(string $userId, string $cnp = '1900101221144'): RegisterPfSubjectInput
{
    return new RegisterPfSubjectInput(
        registeringUserId: $userId,
        registeringUserRoles: ['ADMIN'],
        displayName: 'Ion Popescu',
        firstName: 'Ion',
        lastName: 'Popescu',
        cnp: $cnp,
        birthDate: '1990-01-01',
    );
}

it('creates a PF subject with an OWNER membership for the registering user', function (): void {
    $user = User::factory()->create();

    $subject = app(RegisterPfSubject::class)(registrationInput($user->id));

    expect($subject->type)->toBe(SubjectType::PF);

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

    $subject = app(RegisterPfSubject::class)(registrationInput($user->id));

    $event = DB::table('audit_events')->where('subject_id', $subject->id)->first();

    expect($event)->not->toBeNull()
        ->and($event?->event_type)->toBe('TaxSubjectRegistered')
        ->and($event?->actor_id)->toBe($user->id);

    $verification = app(ChainVerifier::class)->verify($subject->id);
    expect($verification->ok)->toBeTrue();
});

it('rejects a second registration with the same CNP under a different subject', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    app(RegisterPfSubject::class)(registrationInput($firstUser->id, '1900101221144'));

    expect(fn () => app(RegisterPfSubject::class)(registrationInput($secondUser->id, '1 900101 221144')))
        ->toThrow(DuplicateCnpException::class);
});

it('allows two different subjects to register with genuinely different CNPs', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $first = app(RegisterPfSubject::class)(registrationInput($firstUser->id, '1900101221144'));
    $second = app(RegisterPfSubject::class)(registrationInput($secondUser->id, '1900101221145'));

    expect($first->id)->not->toBe($second->id);
});

it('reconnects a reset taxpayer to whoever registers its CNP again, the same row cleaned', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $subject = app(RegisterPfSubject::class)(registrationInput($first->id, '1900101221144'));
    $originalId = $subject->id;

    // Reset needs the tenant bound (its wipe runs tenant-scoped queries), as the endpoint's
    // middleware does in production.
    app(TenantContext::class)->authorizeAndActivate($first->id, $originalId);
    app(ResetTaxSubject::class)($subject, $first->id);

    // A different account adds the same CNP: it reconnects the husk, it does not spawn a new row.
    $reattached = app(RegisterPfSubject::class)(registrationInput($second->id, '1 900101 221144'));

    expect($reattached->id)->toBe($originalId)
        ->and($reattached->status)->toBe(SubjectStatus::ACTIVE);

    // The new account owns it; the old owner's membership stays revoked; the identity is restored.
    $ownedBySecond = SubjectMembership::query()->where('subject_id', $originalId)
        ->where('user_id', $second->id)->whereNull('revoked_at')->exists();
    $ownedByFirst = SubjectMembership::query()->where('subject_id', $originalId)
        ->where('user_id', $first->id)->whereNull('revoked_at')->exists();

    expect($ownedBySecond)->toBeTrue()->and($ownedByFirst)->toBeFalse();
    expect(DB::table('tax_subjects')->where('id', $originalId)->value('fiscal_identifier_ciphertext'))->not->toBeNull();
});

it('reads a reset CNP as available again, revealing nothing of the past', function (): void {
    $first = User::factory()->create();
    $other = User::factory()->create();

    $subject = app(RegisterPfSubject::class)(registrationInput($first->id, '1900101221144'));
    app(TenantContext::class)->authorizeAndActivate($first->id, $subject->id);
    app(ResetTaxSubject::class)($subject, $first->id);

    // Same answer a never-seen CNP gives — not "taken", not "yours".
    $seen = app(FindSubjectByCnp::class)('1900101221144', $other->id);

    expect($seen['registered'])->toBeFalse()
        ->and($seen['mine'])->toBeFalse()
        ->and($seen['subjectId'])->toBeNull();
});
