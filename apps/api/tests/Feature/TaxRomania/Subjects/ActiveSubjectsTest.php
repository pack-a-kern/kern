<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\ActiveSubjectsTest;

use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Actions\RegisterPfSubject;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Exceptions\TooManyActiveSubjectsException;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * A taxpayer is in use or closed; how many may be in use at once (`SUBJECTS_MAX_ACTIVE`); and what the
 * switcher, the taxpayers screen and the account page are handed about them. Made-up names throughout.
 */
function owned(User $user, string $name, SubjectStatus $status = SubjectStatus::ACTIVE): TaxSubject
{
    $subject = TaxSubject::factory()->create(['status' => $status]);
    $subject->display_name_ciphertext = $name;
    $subject->save();
    SubjectMembership::grantOwnership($subject->id, $user->id);

    return $subject;
}

it('hands the switcher every taxpayer in use, and nothing a closed one or another person\'s', function (): void {
    $user = User::factory()->create();
    $active = owned($user, 'Example One');
    owned($user, 'Example Closed', SubjectStatus::CLOSED);
    owned(User::factory()->create(), 'Someone Else');

    $subjects = $this->actingAs($user)->get('/dashboard')->assertOk()->inertiaProps('subjects');

    expect($subjects)->toBe([[
        'id' => $active->id,
        'type' => $active->type->value,
        'display_name' => 'Example One',
        'avatar_updated_at' => null,
    ]]);
});

it('hands the switcher its list again once a taxpayer changes, and not before', function (): void {
    $user = User::factory()->create();
    $subject = owned($user, 'Example One');

    /** @var array{version?: string, onceProps?: array<string, array{prop: string}>} $first the whole page, its once-prop keys included */
    $first = $this->actingAs($user)->get('/dashboard')->assertOk()->viewData('page');
    $key = collect($first['onceProps'] ?? [])->search(static fn (array $once): bool => $once['prop'] === 'subjects');
    $version = (string) ($first['version'] ?? '');
    $again = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Except-Once-Props' => (string) $key];

    expect($this->actingAs($user)->get('/dashboard', $again)->assertOk()->json('props'))->not->toHaveKey('subjects');

    $this->travel(1)->seconds();
    $subject->display_name_ciphertext = 'Example Renamed';
    $subject->save();

    expect($this->actingAs($user)->get('/dashboard', $again)->assertOk()->json('props.subjects.0.display_name'))->toBe('Example Renamed');
});

it('refuses one more taxpayer in use past the limit, counting only those in use', function (): void {
    config()->set('subjects.limits.max_active', 1);
    $user = User::factory()->create();
    owned($user, 'Example Closed', SubjectStatus::CLOSED);
    $register = app(RegisterPfSubject::class);
    $input = fn (string $cnp): RegisterPfSubjectInput => new RegisterPfSubjectInput(
        registeringUserId: $user->id,
        registeringUserRoles: ['SUPER_ADMIN'],
        displayName: 'Example Person',
        firstName: 'Example',
        lastName: 'Person',
        cnp: $cnp,
        birthDate: '1990-01-01',
    );

    $register($input('1900101221144'));

    expect(fn () => $register($input('2900101221145')))->toThrow(TooManyActiveSubjectsException::class);
});

it('refuses to reactivate past the limit, until one in use is closed', function (): void {
    config()->set('subjects.limits.max_active', 1);
    $user = User::factory()->create();
    $inUse = owned($user, 'Example One');
    $closed = owned($user, 'Example Two', SubjectStatus::CLOSED);

    $this->actingAs($user)->putJson("/api/v1/subjects/{$closed->id}/status", ['status' => 'ACTIVE'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'TOO_MANY_ACTIVE_SUBJECTS')
        ->assertJsonPath('limit', 1);

    $this->actingAs($user)->putJson("/api/v1/subjects/{$inUse->id}/status", ['status' => 'CLOSED'])->assertOk();
    $this->actingAs($user)->putJson("/api/v1/subjects/{$closed->id}/status", ['status' => 'ACTIVE'])->assertOk();
});

it('knows no suspended taxpayer any more', function (): void {
    $user = User::factory()->create();
    $subject = owned($user, 'Example One');

    $this->actingAs($user)->putJson("/api/v1/subjects/{$subject->id}/status", ['status' => 'SUSPENDED'])->assertStatus(422);
});

it('says per taxpayer whether an AI may read its documents, and counts both over all of them', function (): void {
    $user = User::factory()->create();
    $consented = owned($user, 'Example One');
    $other = owned($user, 'Example Two');
    $closed = owned($user, 'Example Closed', SubjectStatus::CLOSED);
    $gate = app(DocumentAiConsentGate::class);
    $gate->grant($consented->id);
    $gate->grant($closed->id);
    $gate->grant($other->id);
    $gate->revoke($other->id);

    /** @var list<array{id: string, ai_consent: bool}> $rows */
    $rows = $this->actingAs($user)->getJson('/api/v1/subjects')->assertOk()->json('data');

    expect(array_column($rows, 'ai_consent', 'id'))->toBe([$consented->id => true, $other->id => false, $closed->id => true])
        ->and($this->actingAs($user)->getJson('/api/v1/subjects/counts')->assertOk()->json())->toBe(['active' => 2, 'ai_consented' => 1]);
});
