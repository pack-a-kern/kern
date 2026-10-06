<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\SpvMfaGateTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Identity\Support\StepUpPolicy;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `BLUEPRINT.md` §4.7 — "MFA obligatoriu pentru conturi care conectează SPV."
 *
 * The gate used to live inside two actions and ask `exists()`: had this account enrolled a factor,
 * ever. Anybody holding the session passed it without owning one, and `sync` — reading the inbox,
 * which is the thing worth protecting — did not consult it at all.
 *
 * These cover what replaced it: proof, recently, on every route that acts, and told apart from having
 * nothing to prove with.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/** @return array{0: User, 1: string} */
function member(bool $withTotp = true): array
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

    if ($withTotp) {
        UserMfaMethod::create([
            'user_id' => $user->id,
            'type' => MfaMethodType::TOTP,
            'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
            'confirmed_at' => now('UTC'),
        ]);
    }

    return [$user, $subject->id];
}

it('tells an account with no authenticator to enrol one, rather than offering a dialog it cannot answer', function (): void {
    [$user, $subjectId] = member(withTotp: false);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertForbidden()
        ->assertJsonPath('reason', 'MFA_ENROLLMENT_REQUIRED');
});

it('asks an enrolled account to prove itself, which the enrolment check alone never did', function (): void {
    [$user, $subjectId] = member();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertForbidden()
        ->assertJsonPath('reason', 'VERIFICATION_REQUIRED');
});

it('lets the act through once the session has proved itself', function (): void {
    [$user, $subjectId] = member();
    verifySession();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertCreated();
});

it('stops honouring a proof once the window has passed', function (): void {
    [$user, $subjectId] = member();

    // Verified, then time moves past the window. The marker is a timestamp rather than a flag for
    // exactly this reason: elevation has to stop on its own, with nothing running to expire it.
    verifySession();
    $this->travel(app(StepUpPolicy::class)->windowSeconds() + 1)->seconds();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertForbidden()
        ->assertJsonPath('reason', 'VERIFICATION_REQUIRED');
});

it('guards reading the inbox, which the check it replaced did not cover at all', function (): void {
    [$user, $subjectId] = member();

    verifySession();

    $created = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ]);

    $connectionId = jsonString($created, 'id');

    // The window the registration used is deliberately dropped before syncing, because a sync can
    // happen days later and must be gated on its own.
    session()->forget('auth.step_up_verified_at');

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections/{$connectionId}/sync")
        ->assertForbidden()
        ->assertJsonPath('reason', 'VERIFICATION_REQUIRED');
});

it('does not demand a code to read the list of connections', function (): void {
    [$user, $subjectId] = member();

    // Marked on the acts, not on the reads: a screen that could not draw itself without a dialog
    // would ask for a code before the person had asked for anything.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subjectId}/spv/connections")
        ->assertOk();
});

it('reads the window from configuration, so an installation can set it', function (): void {
    config(['identity.step_up.window_seconds' => 60]);

    expect(app(StepUpPolicy::class)->windowSeconds())->toBe(60);
});
