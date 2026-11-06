<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SessionRegistrar;
use OTPHP\TOTP;

/**
 * `$response->json()` is `mixed`; narrowed once so the TOTP helper gets the non-empty string it
 * declares. An empty secret would silently produce a code that can never verify.
 *
 * @return non-empty-string
 */
function totpSecret(mixed $value): string
{
    expect($value)->toBeString()->not->toBe('');

    return is_string($value) && $value !== '' ? $value : 'AAAAAAAAAAAAAAAA';
}

/**
 * Laravel rotates the session id whenever privilege changes — at login, and again when a second
 * factor is confirmed. `user_sessions` is keyed by that id.
 *
 * A rotation that is not mirrored into the ledger orphans the row: the owner's own session stops
 * matching, is listed as somebody else's device, and cannot be ended from the UI — while "end
 * other sessions" revokes the orphan and leaves the real session running and invisible. That is
 * what this file exists to prevent; it was a live defect, reported as "I revoked all sessions but
 * I can't see mine, and I'm still logged in".
 */
it('moves the session row when the session id rotates', function (): void {
    $user = User::factory()->create();
    $registrar = app(SessionRegistrar::class);

    $registrar->register($user->id, 'old-session-id', '10.0.0.1', 'Test/1.0');
    $registrar->rotate('old-session-id', 'new-session-id', $user->id, '10.0.0.1', 'Test/1.0');

    expect(DB::table('user_sessions')->where('id', 'old-session-id')->exists())->toBeFalse()
        ->and(DB::table('user_sessions')->where('id', 'new-session-id')->exists())->toBeTrue()
        // The row moves rather than being recreated, so the device's history is preserved.
        ->and(DB::table('user_sessions')->count())->toBe(1)
        ->and(DB::table('user_sessions')->where('id', 'new-session-id')->value('user_agent'))->toBe('Test/1.0');
});

it('registers a row when there was nothing to rotate', function (): void {
    $user = User::factory()->create();

    app(SessionRegistrar::class)->rotate('never-existed', 'fresh', $user->id, null, null);

    expect(DB::table('user_sessions')->where('id', 'fresh')->exists())->toBeTrue();
});

it('leaves a revoked row revoked instead of resurrecting it under a new id', function (): void {
    // A revoked session must not come back to life because the id happened to rotate: that would
    // undo a deliberate "end this session" the moment the device made one more request.
    $user = User::factory()->create();
    $registrar = app(SessionRegistrar::class);

    $registrar->register($user->id, 'revoked-session', null, null);
    DB::table('user_sessions')->where('id', 'revoked-session')->update(['revoked_at' => now('UTC')]);

    $registrar->rotate('revoked-session', 'rotated', $user->id, null, null);

    expect(DB::table('user_sessions')->where('id', 'revoked-session')->whereNotNull('revoked_at')->exists())
        ->toBeTrue();
});

it('leaves no orphaned session row behind when TOTP is confirmed', function (): void {
    // The end-to-end shape of the reported defect. `actingAs()` does not go through the login
    // controller, so the ledger starts empty and the id the test client uses is not stable across
    // requests — which makes `is_current` untestable here. What *is* testable, and is exactly what
    // broke, is that confirming TOTP must not leave a row pointing at a session id that no longer
    // exists: before the fix this produced two rows, one of them permanently unreachable.
    $user = User::factory()->create();

    $this->actingAs($user);
    verifySession(); // enrolling an authenticator is behind `verify-recent`

    $secret = totpSecret($this->postJson('/api/v1/auth/mfa/totp/enroll')->assertOk()->json('secret'));

    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()])
        ->assertOk();

    expect(DB::table('user_sessions')->where('user_id', $user->getKey())->count())->toBeLessThanOrEqual(1);
});

it('removes recovery codes when the authenticator app is disabled', function (): void {
    // `CompleteMfaChallenge` matches recovery codes independently of TOTP, so codes left behind
    // would still authenticate an account whose owner was told the second factor is gone.
    $user = User::createWithEmail('disable-' . Str::random(6) . '@example.test', Hash::make('a-long-enough-password'));

    $this->actingAs($user);
    verifySession(); // enrolling an authenticator is behind `verify-recent`

    $secret = totpSecret($this->postJson('/api/v1/auth/mfa/totp/enroll')->assertOk()->json('secret'));
    $code = TOTP::createFromSecret($secret)->now();
    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $code])->assertOk();

    expect($user->recoveryCodes()->whereNull('used_at')->count())->toBeGreaterThan(0);

    $this->deleteJson('/api/v1/auth/mfa/totp', ['current_password' => 'a-long-enough-password'])->assertOk();

    expect($user->mfaMethods()->where('type', MfaMethodType::TOTP)->exists())->toBeFalse()
        ->and($user->recoveryCodes()->whereNull('used_at')->count())->toBe(0);
});

it('refuses to disable the authenticator without the current password', function (): void {
    $user = User::createWithEmail('guard-' . Str::random(6) . '@example.test', Hash::make('a-long-enough-password'));

    $this->actingAs($user);
    verifySession(); // enrolling an authenticator is behind `verify-recent`

    $secret = totpSecret($this->postJson('/api/v1/auth/mfa/totp/enroll')->assertOk()->json('secret'));
    $code = TOTP::createFromSecret($secret)->now();
    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => $code])->assertOk();

    $this->deleteJson('/api/v1/auth/mfa/totp', ['current_password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect($user->mfaMethods()->where('type', MfaMethodType::TOTP)->exists())->toBeTrue();
});

it('stamps last_used_at on an authenticated request, so a session in use is not pruned as idle', function (): void {
    // Before this, `last_used_at` moved only at login and rotation, so the hourly prune measured
    // idleness from login and deleted the ledger row of a session someone was still using — taking it
    // out of the device list and beyond the reach of "sign out everywhere".
    $user = User::factory()->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();

    carryCookiesFrom(
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email_ciphertext,
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()
    );

    $sessionId = DB::table('user_sessions')->where('user_id', $user->getKey())->value('id');
    expect($sessionId)->not->toBeNull();

    // Hours of real use, with no login or rotation to move the ledger clock on their own.
    $this->travel(5)->hours();
    carryCookiesFrom($this->getJson('/api/v1/me')->assertOk());

    $lastUsed = DB::table('user_sessions')->where('id', $sessionId)->value('last_used_at');
    expect($lastUsed)->toBeString();
    $stampedAt = is_scalar($lastUsed) ? (string) $lastUsed : '';
    expect(Carbon::parse($stampedAt)->greaterThan(now('UTC')->subMinute()))->toBeTrue();
});

it('writes last_used_at at most every few minutes, not on every request', function (): void {
    $user = User::factory()->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();

    carryCookiesFrom(
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email_ciphertext,
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()
    );

    $writes = static function (callable $requests): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $requests();

        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_starts_with($query['query'], 'update') && str_contains($query['query'], 'user_sessions'),
        ));
    };

    // The first request writes; the next ones, within the interval, do not.
    $this->travel(1)->minutes();
    expect($writes(function (): void {
        foreach (range(1, 5) as $ignored) {
            carryCookiesFrom($this->getJson('/api/v1/me')->assertOk());
        }
    }))->toBe(1);

    // Once the interval has passed, the next request writes again.
    $this->travel(SessionRegistrar::TOUCH_INTERVAL_MINUTES)->minutes();
    expect($writes(fn () => carryCookiesFrom($this->getJson('/api/v1/me')->assertOk())))->toBe(1);
});
