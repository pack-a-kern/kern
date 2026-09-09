<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\RegisterPfSubjectControllerTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\PfProfile;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Support\KeyManagement\SubjectDekService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * `POST /api/v1/subjects/pf` — the HTTP entry point onto `App\Domain\Subjects\Actions\
 * RegisterPfSubject`, which `docs/handoff/030-pwa-frontend.md`'s Key decision #1 found had no
 * controller at all.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function validPfPayload(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Ion Popescu',
        'first_name' => 'Ion',
        'last_name' => 'Popescu',
        'cnp' => '1900101221144',
        'birth_date' => '1990-01-01',
        'county_code' => 'CJ',
        'locality' => 'Cluj-Napoca',
        'address' => 'Str. Exemplu nr. 1',
    ], $overrides);
}

it('registers a PF subject and makes the caller its OWNER', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/subjects/pf', validPfPayload())
        ->assertCreated();

    $subjectId = $response->json('id');
    expect($subjectId)->toBeString();

    $membership = SubjectMembership::query()
        ->where('subject_id', $subjectId)
        ->where('user_id', $user->id)
        ->first();

    expect($membership)->not->toBeNull();
    assert($membership !== null);
    expect($membership->role)->toBe(MembershipRole::OWNER)
        ->and($membership->revoked_at)->toBeNull();

    $profile = PfProfile::query()->find($subjectId);
    expect($profile)->not->toBeNull();
});

it('rejects a duplicate CNP with a structured 422', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA, 'web')->postJson('/api/v1/subjects/pf', validPfPayload())->assertCreated();

    $response = $this->actingAs($userB, 'web')
        ->postJson('/api/v1/subjects/pf', validPfPayload(['display_name' => 'Someone Else']))
        ->assertStatus(422);

    expect($response->json('reason'))->toBe('DUPLICATE_CNP');
});

it('validates required fields', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/subjects/pf', validPfPayload(['cnp' => '']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cnp']);

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/subjects/pf', validPfPayload(['birth_date' => 'not-a-date']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['birth_date']);
});

it('requires authentication', function (): void {
    $this->postJson('/api/v1/subjects/pf', validPfPayload())->assertStatus(401);
});
