<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\RegisterPfSubjectControllerTest;

use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\PfProfile;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;

/**
 * `POST /api/v1/subjects/pf` — the HTTP entry point onto `Kern\TaxRomania\Subjects\Actions\
 * RegisterPfSubject`, which `docs/handoff/030-pwa-frontend.md`'s Key decision #1 found had no
 * controller at all.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
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
        'cnp' => cnpWithCheckDigit('190010122114'),
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

it('registers a CNP that names no county with the county the person picked', function (): void {
    $user = User::factory()->create();
    // 70: generated since 8 May 2024, naming no county.
    $cnp = cnpWithCheckDigit('5240601' . '70' . '123');

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/subjects/cnp', ['cnp' => $cnp])
        ->assertOk()
        ->assertJsonPath('county_code', null);

    $subjectId = $this->actingAs($user, 'web')
        ->postJson('/api/v1/subjects/pf', validPfPayload(['cnp' => $cnp, 'county_code' => 'SB']))
        ->assertCreated()
        ->json('id');
    assert(is_string($subjectId));

    expect(PfProfile::query()->whereKey($subjectId)->value('county_code'))->toBe('SB');
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

    // The birth date is not a request field any more — it is read out of the CNP — so a CNP whose
    // check digit is wrong is what a bad date looks like now.
    $this->actingAs($user, 'web')
        ->postJson('/api/v1/subjects/pf', validPfPayload(['cnp' => '1234567890123']))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'CNP_CHECKSUM');
});

it('requires authentication', function (): void {
    $this->postJson('/api/v1/subjects/pf', validPfPayload())->assertStatus(401);
});
