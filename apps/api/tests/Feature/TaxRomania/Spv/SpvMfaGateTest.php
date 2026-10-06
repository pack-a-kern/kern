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
 * The step-up gate on the SPV routes.
 *
 * It used to live inside two actions and ask `exists()`: had this account enrolled a factor, ever.
 * Anybody holding the session passed it without owning one, and `sync` — reading the inbox, which is
 * the thing worth protecting — did not consult it at all.
 *
 * What replaced it asks for proof, recently, on every route that acts, **of an account that has turned
 * two-factor authentication on**. That last clause is a deliberate departure from `BLUEPRINT.md` §4.7's
 * "MFA obligatoriu": nothing in this installation forces enrolment, so a gate that refused instead
 * would be forcing it at the moment somebody tried to use a screen. Whether to require a second factor
 * is the account's own setting; what this guarantees is that a factor which *is* enabled gets used.
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

it('lets an account with no authenticator through, because there is nothing to challenge', function (): void {
    [$user, $subjectId] = member(withTotp: false);

    // The gate exists for an account once that account turns TOTP on. The alternative — demanding
    // enrolment here — would force a security decision inside a dialog in order to get past a screen,
    // and the security screen is where it belongs.
    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subjectId}/spv/connections", [
            'auth_method' => 'USER_PASSWORD_OTP',
            'username' => 'contribuabil.test',
        ])
        ->assertCreated();
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
