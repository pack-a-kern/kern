<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Identity\SessionManagementTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserSession;
use Tests\TestCase;

/**
 * `BLUEPRINT.md` §4.7's device/session management: listing, explicit single-session revoke, explicit
 * "revoke all", and logout — each checked against both `user_sessions` (the listing table) and the
 * real session store (a revoked session cookie must stop working, not just disappear from a list).
 *
 * Every multi-request flow here uses `carryCookiesFrom()` (`tests/Pest.php`) to replay the real
 * session cookie between calls — without it, every simulated request gets a fresh, unrelated
 * session and "which session is current" assertions would be meaningless.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function loggedInUser(TestCase $test, string $email = 'ion@example.com', string $password = 'correct-horse-battery-staple'): User
{
    $user = User::factory()->withEmail($email)->create();
    $user->password_hash = Hash::make($password);
    $user->save();

    carryCookiesFrom($test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk());

    return $user;
}

it('lists the current session, marked as current', function (): void {
    loggedInUser($this);

    $response = carryCookiesFrom($this->getJson('/api/v1/auth/sessions')->assertOk());

    /** @var list<array{id: string, ip_address: ?string, user_agent: ?string, last_used_at: string, created_at: string, is_current: bool}> $sessions */
    $sessions = $response->json('sessions');

    expect($sessions)->toHaveCount(1);
    expect($sessions[0]['is_current'])->toBeTrue();
    expect($sessions[0])->toHaveKeys(['id', 'ip_address', 'user_agent', 'last_used_at', 'created_at']);
});

it('revokes a specific session by id, destroying its underlying session data', function (): void {
    loggedInUser($this);

    $sessionId = UserSession::query()->sole()->id;

    // Prove the real session-store entry exists before revocation — otherwise "it's gone
    // afterwards" would be trivially true for the wrong reason.
    expect($this->app['session']->getHandler()->read($sessionId))->not->toBe('');

    carryCookiesFrom(
        $this->deleteJson("/api/v1/auth/sessions/{$sessionId}")->assertOk()->assertJson(['revoked' => true]),
    );

    $session = UserSession::query()->findOrFail($sessionId);
    expect($session->revoked_at)->not->toBeNull();

    // The underlying session-store entry is destroyed, not merely flagged in `user_sessions` —
    // a genuinely new request (a fresh PHP process in production) reading this session id from
    // the real store would find nothing and fail to authenticate. This is checked directly
    // against the handler rather than via a second simulated HTTP round-trip: within one Pest
    // test process the `Auth` guard is a container singleton that caches the resolved user
    // in memory regardless of session-id churn (tests/Pest.php's `carryCookiesFrom()` docblock),
    // so a follow-up `getJson()` call here would still look authenticated for reasons that have
    // nothing to do with whether this endpoint's revocation actually worked.
    expect($this->app['session']->getHandler()->read($sessionId))->toBe('');
});

it('refuses to revoke a session belonging to another user', function (): void {
    $victim = loggedInUser($this, 'victim@example.com');
    UserSession::query()->create([
        'id' => 'someone-elses-session-id',
        'user_id' => $victim->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'test-agent',
        'last_used_at' => now('UTC'),
        'created_at' => now('UTC'),
    ]);

    carryCookiesFrom($this->postJson('/api/v1/auth/logout')->assertOk());

    // A different account's login response's Set-Cookie carries the same cookie *name*
    // (config('session.cookie') is app-wide, not per-user), so carrying it forward via
    // loggedInUser() below naturally overwrites the victim's cookie value in $defaultCookies —
    // no separate "new browser" reset is needed.
    loggedInUser($this, 'attacker@example.com', 'another-strong-password');

    $this->deleteJson('/api/v1/auth/sessions/someone-elses-session-id')->assertStatus(422);

    $victimSession = UserSession::query()->findOrFail('someone-elses-session-id');
    expect($victimSession->revoked_at)->toBeNull();
});

it('revokes every other session but keeps the caller logged in when keep_current is true', function (): void {
    loggedInUser($this);
    $currentSessionId = UserSession::query()->where('revoked_at', null)->sole()->id;

    // Simulate a second device by inserting another active session row for the same user.
    UserSession::query()->create([
        'id' => 'another-device-session-id',
        'user_id' => UserSession::query()->findOrFail($currentSessionId)->user_id,
        'ip_address' => '10.0.0.2',
        'user_agent' => 'another-device',
        'last_used_at' => now('UTC'),
        'created_at' => now('UTC'),
    ]);

    $response = carryCookiesFrom($this->deleteJson('/api/v1/auth/sessions?keep_current=1')->assertOk());

    expect($response->json('revoked_count'))->toBe(1);

    expect(UserSession::query()->findOrFail($currentSessionId)->revoked_at)->toBeNull();
    expect(UserSession::query()->findOrFail('another-device-session-id')->revoked_at)->not->toBeNull();

    // The caller's own (kept) session cookie still authenticates.
    $this->getJson('/api/v1/auth/sessions')->assertOk();
});

it('revokes every session including the current one when keep_current is false', function (): void {
    loggedInUser($this);
    $sessionId = UserSession::query()->sole()->id;

    $response = carryCookiesFrom(
        $this->deleteJson('/api/v1/auth/sessions?keep_current=0')->assertOk(),
    );

    expect($response->json('revoked_count'))->toBe(1);
    expect(UserSession::query()->findOrFail($sessionId)->revoked_at)->not->toBeNull();
    expect($this->app['session']->getHandler()->read($sessionId))->toBe('');
});

it('logout revokes the current session and clears authentication', function (): void {
    loggedInUser($this);
    $sessionId = UserSession::query()->sole()->id;

    carryCookiesFrom(
        $this->postJson('/api/v1/auth/logout')->assertOk()->assertJson(['loggedOut' => true]),
    );

    expect(UserSession::query()->findOrFail($sessionId)->revoked_at)->not->toBeNull();
    $this->getJson('/api/v1/auth/sessions')->assertUnauthorized();
});
