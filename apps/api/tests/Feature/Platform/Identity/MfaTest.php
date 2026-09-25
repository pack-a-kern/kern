<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\MfaTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserRecoveryCode;
use Kern\Platform\Identity\Support\Totp\TotpVerifier;
use OTPHP\TOTP;
use Tests\TestCase;

/**
 * `BLUEPRINT.md` §4.7's MFA fallback (TOTP) and recovery codes: enrollment, the clock-skew window,
 * single-use recovery codes, and the full login → MFA → session lifecycle.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function loginUser(TestCase $test, User $user, string $password): void
{
    $test->postJson('/api/v1/auth/login', ['email' => $user->email_ciphertext, 'password' => $password])
        ->assertOk()
        ->assertJson(['mfa_required' => false]);
}

it('enrolls TOTP, confirms it with a valid code, and issues recovery codes exactly once', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    loginUser($this, $user, 'correct-horse-battery-staple');

    $enroll = $this->postJson('/api/v1/auth/mfa/totp/enroll')->assertOk();
    $secret = jsonString($enroll, 'secret');

    $code = TOTP::createFromSecret($secret)->now();

    $confirm = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $code])->assertOk();
    $codes = jsonStringList($confirm, 'recovery_codes');

    expect($codes)->toHaveCount(10);

    $status = $this->getJson('/api/v1/auth/mfa/status')->assertOk();
    expect($status->json('totp_enrolled'))->toBeTrue();
    expect($status->json('recovery_codes_remaining'))->toBe(10);
});

it('rejects an invalid confirmation code and does not activate the method', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    loginUser($this, $user, 'correct-horse-battery-staple');

    $this->postJson('/api/v1/auth/mfa/totp/enroll')->assertOk();
    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => '000000'])->assertStatus(422);

    $status = $this->getJson('/api/v1/auth/mfa/status')->assertOk();
    expect($status->json('totp_enrolled'))->toBeFalse();
});

it('accepts a code from one period of clock skew, but not from further away', function (): void {
    $verifier = new TotpVerifier();
    $secret = $verifier->generateSecret();
    $totp = TOTP::createFromSecret($secret);

    $now = 1_700_000_000; // fixed reference instant, far from the epoch boundary either direction
    $oneStepBack = $totp->at($now - 30);
    $threeStepsBack = $totp->at($now - 90);

    expect($verifier->verify($secret, $oneStepBack, $now))->toBeTrue();
    expect($verifier->verify($secret, $threeStepsBack, $now))->toBeFalse();
});

it('completes a login that required MFA, via the mfa/challenge endpoint', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    loginUser($this, $user, 'correct-horse-battery-staple');

    $secret = jsonString($this->postJson('/api/v1/auth/mfa/totp/enroll'), 'secret');
    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()])->assertOk();
    $this->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertGuest();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk()->assertJson(['mfa_required' => true]);

    $this->assertGuest();

    $this->postJson('/api/v1/auth/mfa/challenge', [
        'code' => TOTP::createFromSecret($secret)->now(),
    ])->assertOk();

    $this->assertAuthenticated();
});

it('accepts a recovery code exactly once and rejects a replay', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    loginUser($this, $user, 'correct-horse-battery-staple');

    $secret = jsonString($this->postJson('/api/v1/auth/mfa/totp/enroll'), 'secret');
    $codes = jsonStringList(
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()]),
        'recovery_codes',
    );
    $this->postJson('/api/v1/auth/logout')->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertJson(['mfa_required' => true]);

    $firstUse = $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $codes[0]]);
    $firstUse->assertOk();
    $this->assertAuthenticated();

    $this->postJson('/api/v1/auth/logout')->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertJson(['mfa_required' => true]);

    $replay = $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $codes[0]]);
    $replay->assertStatus(422);
    $this->assertGuest();

    expect(UserRecoveryCode::query()->where('user_id', $user->id)->whereNotNull('used_at')->count())->toBe(1);
});

it('rate limits the MFA challenge endpoint per user', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    loginUser($this, $user, 'correct-horse-battery-staple');

    $secret = jsonString($this->postJson('/api/v1/auth/mfa/totp/enroll'), 'secret');
    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()])->assertOk();
    $this->postJson('/api/v1/auth/logout')->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertJson(['mfa_required' => true]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertStatus(429);
});

it('regenerates recovery codes and invalidates the old set', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    loginUser($this, $user, 'correct-horse-battery-staple');

    $secret = jsonString($this->postJson('/api/v1/auth/mfa/totp/enroll'), 'secret');
    $oldCodes = jsonStringList(
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()]),
        'recovery_codes',
    );

    $newCodes = jsonStringList(
        $this->postJson('/api/v1/auth/mfa/recovery-codes/regenerate')->assertOk(),
        'recovery_codes',
    );

    expect($newCodes)->toHaveCount(10);
    expect(array_intersect($oldCodes, $newCodes))->toBeEmpty();

    $this->postJson('/api/v1/auth/logout')->assertOk();
    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertJson(['mfa_required' => true]);

    // The old (regenerated-away) code no longer works.
    $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $oldCodes[0]])->assertStatus(422);
});
