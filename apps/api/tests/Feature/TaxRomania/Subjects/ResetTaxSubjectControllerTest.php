<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\ResetTaxSubjectControllerTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Actions\RegisterPfSubject;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/** A real owned PF taxpayer, with its membership and audit chain, for the owner to reset. */
function ownedSubject(User $owner): TaxSubject
{
    return app(RegisterPfSubject::class)(new RegisterPfSubjectInput(
        registeringUserId: $owner->id,
        registeringUserRoles: ['ADMIN'],
        displayName: 'Ion Popescu',
        firstName: 'Ion',
        lastName: 'Popescu',
        cnp: '1900101221144',
        birthDate: '1990-01-01',
    ));
}

it('lets the owner reset the taxpayer, revoking their membership', function (): void {
    $owner = User::factory()->create();
    $subject = ownedSubject($owner);

    $this->actingAs($owner)
        ->postJson("/api/v1/subjects/{$subject->id}/reset")
        ->assertOk()
        ->assertJson(['id' => $subject->id, 'reset' => true]);

    // The reset ran: the owner's membership is revoked, so the taxpayer has left their list.
    expect(SubjectMembership::query()->where('subject_id', $subject->id)->whereNull('revoked_at')->exists())
        ->toBeFalse();
});

it('refuses a non-owner member, leaving the taxpayer untouched', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    foreach ([[$owner, MembershipRole::OWNER], [$member, MembershipRole::VIEWER]] as [$user, $role]) {
        SubjectMembership::create([
            'subject_id' => $subject->id,
            'user_id' => $user->id,
            'role' => $role,
            'permissions_json' => [],
            'valid_from' => now('UTC'),
        ]);
    }

    $this->actingAs($member)
        ->postJson("/api/v1/subjects/{$subject->id}/reset")
        ->assertForbidden();

    // Nothing was revoked: the owner still holds it.
    expect(SubjectMembership::query()->where('subject_id', $subject->id)->where('user_id', $owner->id)
        ->whereNull('revoked_at')->exists())->toBeTrue();
});

it('refuses an unauthenticated caller', function (): void {
    $subject = TaxSubject::factory()->create();

    $this->postJson("/api/v1/subjects/{$subject->id}/reset")->assertUnauthorized();
});
