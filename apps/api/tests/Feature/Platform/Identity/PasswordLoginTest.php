<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\PasswordLoginTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Identity\Models\UserSession;

/**
 * `POST /api/v1/auth/login` — `BLUEPRINT.md` §4.7's password login and its user-enumeration
 * resistance (§4.1, `docs/AGENTS.md` §8). See `Kern\Platform\Identity\Actions\AttemptPasswordLogin`'s
 * own docblock for the exact mechanism the timing test below exercises.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function createUserWithPassword(string $email, string $password): User
{
    $user = User::factory()->withEmail($email)->create();
    $user->password_hash = Hash::make($password);
    $user->save();

    return $user;
}

it('logs a user in with no MFA enrolled, establishing a session immediately', function (): void {
    createUserWithPassword('ion@example.com', 'correct-horse-battery-staple');

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertOk()->assertJson(['mfa_required' => false]);
    $this->assertAuthenticated();

    expect(UserSession::query()->count())->toBe(1);
});

it('rotates the session id on a successful login (fixation resistance)', function (): void {
    createUserWithPassword('ion@example.com', 'correct-horse-battery-staple');

    // A failed attempt still passes through the "web" middleware group (StartSession), which is
    // enough to establish a pre-login session id to compare against. carryCookiesFrom() replays
    // the real Set-Cookie header on the next request — Laravel's TestCase does not do this
    // automatically (tests/Pest.php's own docblock on that helper) — so this genuinely proves the
    // *same browser session* got a new id, not merely "two unrelated requests had different ids".
    carryCookiesFrom($this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'the-wrong-password',
    ])->assertStatus(422));
    $idBefore = $this->app['session']->getId();

    carryCookiesFrom($this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk());

    $idAfter = $this->app['session']->getId();

    expect($idAfter)->not->toBe($idBefore);
    expect(UserSession::query()->whereKey($idAfter)->exists())->toBeTrue();
    expect(UserSession::query()->whereKey($idBefore)->exists())->toBeFalse();
});

it('requires MFA when the account has a confirmed TOTP method, without establishing a session', function (): void {
    $user = createUserWithPassword('mfa@example.com', 'correct-horse-battery-staple');
    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'mfa@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertOk()->assertJson(['mfa_required' => true]);
    $this->assertGuest();
    expect(UserSession::query()->count())->toBe(0);
});

it('rejects an unknown email and a wrong password with the identical response', function (): void {
    createUserWithPassword('real@example.com', 'correct-horse-battery-staple');

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever',
    ]);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'the-wrong-password',
    ]);

    $unknownEmail->assertStatus(422);
    $wrongPassword->assertStatus(422);
    expect($unknownEmail->json('errors.email.0'))->toBe($wrongPassword->json('errors.email.0'));
    $this->assertGuest();
});

it('takes roughly the same time whether the email is unknown or the password is wrong', function (): void {
    createUserWithPassword('real@example.com', 'correct-horse-battery-staple');

    $timeFor = function (string $email, string $password): float {
        $start = hrtime(true);
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);

        return (hrtime(true) - $start) / 1_000_000_000;
    };

    // Warm the dummy-hash cache (Cache::rememberForever) before measuring, so the first call's
    // one-time Argon2id computation doesn't skew the comparison.
    $timeFor('unknown-warmup@example.com', 'whatever');

    $unknownEmailSeconds = $timeFor('nobody@example.com', 'whatever');
    $wrongPasswordSeconds = $timeFor('real@example.com', 'the-wrong-password');

    $ratio = max($unknownEmailSeconds, $wrongPasswordSeconds) / max(min($unknownEmailSeconds, $wrongPasswordSeconds), 0.0001);

    expect($ratio)->toBeLessThan(3.0);
})->skip((bool) getenv('CI_SKIP_TIMING_TESTS'), 'Timing tests are inherently noisy under shared CI runners.');

it('is rate limited per account+IP without locking out other accounts', function (): void {
    createUserWithPassword('victim@example.com', 'correct-horse-battery-staple');
    createUserWithPassword('other@example.com', 'correct-horse-battery-staple');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    // The 6th attempt against the same account is throttled.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'victim@example.com',
        'password' => 'wrong',
    ])->assertStatus(429);

    // A different account, from the same IP, is unaffected by the victim's exhausted bucket.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'other@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk();
});
