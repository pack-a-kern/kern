<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\DocumentAiConsentControllerTest;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;

/**
 * `POST`/`DELETE /api/v1/subjects/{subject}/document-ai-consent` — ADR 0005's consent gate, the
 * HTTP surface a future settings screen calls.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

/** @return array{0: User, 1: TaxSubject} */
function memberAndSubject(): array
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

    return [$user, $subject];
}

it('grants and then revokes consent through the HTTP endpoints', function (): void {
    [$user, $subject] = memberAndSubject();
    $gate = app(DocumentAiConsentGate::class);

    expect($gate->isConsented($subject->id))->toBeFalse();

    $this->actingAs($user, 'web')->postJson("/api/v1/subjects/{$subject->id}/document-ai-consent")
        ->assertOk()->assertJson(['consented' => true]);
    expect($gate->isConsented($subject->id))->toBeTrue();

    $this->actingAs($user, 'web')->deleteJson("/api/v1/subjects/{$subject->id}/document-ai-consent")
        ->assertOk()->assertJson(['consented' => false]);
    expect($gate->isConsented($subject->id))->toBeFalse();
});

it('refuses to grant consent for a subject the user has no membership on', function (): void {
    [, $subject] = memberAndSubject();
    $outsider = User::factory()->create();

    $this->actingAs($outsider, 'web')->postJson("/api/v1/subjects/{$subject->id}/document-ai-consent")
        ->assertStatus(403);

    expect(app(DocumentAiConsentGate::class)->isConsented($subject->id))->toBeFalse();
});
