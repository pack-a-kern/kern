<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\DepositTermPreviewTest;

use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/** @return array{0: User, 1: string} an owner and their subject */
function owner(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    SubjectMembership::create(['subject_id' => $subject->id, 'user_id' => $user->id, 'role' => MembershipRole::OWNER, 'permissions_json' => [], 'valid_from' => now('UTC')]);

    return [$user, (string) $subject->getKey()];
}

/** `GET …/wallet/deposits/term` — the form's preview, worked out by the rules a saved deposit is. */
it('answers the maturity, whether a weekend moved it, and its days', function (): void {
    [$user, $subjectId] = owner();

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/deposits/term?starts_at=2026-05-01&term_months=6")
        ->assertOk()
        ->assertExactJson(['maturityAt' => '2026-11-02', 'movedFromWeekend' => true, 'days' => 185]);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/deposits/term?starts_at=2026-04-30&term_months=6")
        ->assertOk()
        ->assertJsonPath('movedFromWeekend', false);
});

it('refuses a start that is not a date and a term outside the ones offered', function (string $query, string $field): void {
    [$user, $subjectId] = owner();

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/deposits/term?{$query}")
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    ['starts_at=2026-13-01&term_months=6', 'starts_at'],
    ['starts_at=2026-05-01&term_months=0', 'term_months'],
    ['starts_at=2026-05-01&term_months=85', 'term_months'],
]);

it('answers nobody but a member of the subject', function (): void {
    [, $subjectId] = owner();

    $this->getJson("/api/v1/subjects/{$subjectId}/wallet/deposits/term?starts_at=2026-05-01&term_months=6")->assertUnauthorized();
    $this->actingAs(User::factory()->create())
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/deposits/term?starts_at=2026-05-01&term_months=6")
        ->assertForbidden();
});
