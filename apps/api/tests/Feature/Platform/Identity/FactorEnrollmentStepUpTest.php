<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\FactorEnrollmentStepUpTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Models\User;
use OTPHP\TOTP;
use Tests\TestCase;

/**
 * Adding a login factor is a sensitive act and must sit behind `verify-recent`.
 *
 * Without that gate a stolen session could enrol its own authenticator or passkey, confirm it, and
 * then clear `step-up` with the factor it just added — satisfying every gate that trusts step-up
 * (assessments, SPV, ending other sessions) without ever producing the account's password. The gate
 * is answerable by password, which a session thief does not have, so the loop closes at enrolment.
 *
 * docs/SECURITY-FINDINGS.md — the step-up self-bootstrap finding.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function signIn(TestCase $test, string $password = 'correct-horse-battery-staple'): User
{
    $user = User::factory()->withEmail('factor@example.com')->create();
    $user->password_hash = Hash::make($password);
    $user->save();

    $test->postJson('/api/v1/auth/login', ['email' => 'factor@example.com', 'password' => $password])
        ->assertOk()
        ->assertJson(['mfa_required' => false]);

    // A fresh login has not stepped up. Clear any marker a previous test left on the shared session
    // store so the refusal assertions below mean what they say.
    forgetVerification();

    return $user;
}

it('refuses to enrol an authenticator on a session that has not stepped up', function (): void {
    signIn($this);

    $this->postJson('/api/v1/auth/mfa/totp/enroll')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'PASSWORD_CONFIRMATION_REQUIRED');
});

it('refuses to begin a passkey registration without stepping up', function (): void {
    signIn($this);

    $this->postJson('/api/v1/auth/passkeys/registration/options')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'PASSWORD_CONFIRMATION_REQUIRED');
});

it('refuses to regenerate recovery codes without stepping up', function (): void {
    signIn($this);

    $this->postJson('/api/v1/auth/mfa/recovery-codes/regenerate')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'PASSWORD_CONFIRMATION_REQUIRED');
});

it('lets a stepped-up session enrol and confirm an authenticator', function (): void {
    signIn($this);
    verifySession();

    $secret = jsonString($this->postJson('/api/v1/auth/mfa/totp/enroll')->assertOk(), 'secret');
    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()])
        ->assertOk();
});
