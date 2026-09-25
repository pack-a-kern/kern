<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\RegisterPfSubjectTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Audit\Services\ChainVerifier;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\TaxRomania\Subjects\Actions\RegisterPfSubject;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Exceptions\DuplicateCnpException;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Tests\Fakes\InMemoryKeyManagementService;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $provider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
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
