<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\ListSubjectsControllerTest;

use Illuminate\Testing\TestResponse;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;

/**
 * `GET /api/v1/subjects` — `docs/handoff/030-pwa-frontend.md`'s Key decision #1: the real
 * subject listing that replaces the frontend's `localStorage`-only "type your subject id"
 * workaround.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/**
 * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
 *
 * @return array<int, array<string, mixed>>
 */
function dataRows(TestResponse $response): array
{
    $data = $response->json('data');

    if (! is_array($data)) {
        throw new RuntimeException('Expected a "data" array in the response.');
    }

    return array_map(static function (mixed $row): array {
        if (! is_array($row)) {
            throw new RuntimeException('Expected each "data" row to be an array.');
        }

        return $row;
    }, $data);
}

function membershipFor(User $user, TaxSubject $subject, MembershipRole $role = MembershipRole::OWNER, ?\Illuminate\Support\Carbon $revokedAt = null): SubjectMembership
{
    return SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => $role,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
        'revoked_at' => $revokedAt,
    ]);
}

it('lists every subject the user holds an active membership on, with role and decrypted display name', function (): void {
    $user = User::factory()->create();
    $subjectA = TaxSubject::factory()->create();
    $subjectB = TaxSubject::factory()->create();

    membershipFor($user, $subjectA, MembershipRole::OWNER);
    membershipFor($user, $subjectB, MembershipRole::VIEWER);

    $response = $this->actingAs($user, 'web')->getJson('/api/v1/subjects')->assertOk();

    $ids = collect(dataRows($response))->pluck('id');
    expect($ids)->toContain($subjectA->id, $subjectB->id);

    $rowA = collect(dataRows($response))->firstWhere('id', $subjectA->id);
    expect($rowA)
        ->toMatchArray([
            'id' => $subjectA->id,
            'type' => 'PF',
            'display_name' => $subjectA->display_name_ciphertext,
            'status' => 'ACTIVE',
            'role' => 'OWNER',
        ]);

    // Numbered-page envelope, not a bare array: the taxpayers table shows "how many" and offers a
    // page to jump to, neither of which a cursor can answer.
    expect($response->json())->toHaveKeys(['data', 'path', 'per_page', 'current_page', 'last_page', 'total']);
});

it('never lists a subject whose membership was revoked', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    membershipFor($user, $subject, MembershipRole::OWNER, now('UTC'));

    $response = $this->actingAs($user, 'web')->getJson('/api/v1/subjects')->assertOk();

    expect(collect(dataRows($response))->pluck('id'))->not->toContain($subject->id);
});

it('never lists another user\'s subject', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $subjectB = TaxSubject::factory()->create();

    membershipFor($userB, $subjectB, MembershipRole::OWNER);

    $response = $this->actingAs($userA, 'web')->getJson('/api/v1/subjects')->assertOk();

    expect(collect(dataRows($response))->pluck('id'))->not->toContain($subjectB->id);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/subjects')->assertStatus(401);
});
