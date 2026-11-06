<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Testing\PendingCommand;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserSession;
use Kern\Platform\Identity\Support\SessionRegistrar;
use Tests\TestCase;

beforeEach(function (): void {
    // Fixed so "live" and "expired" are the test's statements rather than the suite's timing.
    Config::set('session.lifetime', 120);
    Config::set('identity.sessions.prune_grace_minutes', 60);
});

/**
 * Writes a ledger row last used `$minutesAgo` ago.
 *
 * Through the model rather than the registrar, because the registrar always stamps `now()` — the
 * whole point here is a row older than the clock.
 */
function ledgerRow(User $user, int $minutesAgo, bool $revoked = false): UserSession
{
    $moment = now('UTC')->subMinutes($minutesAgo);

    return UserSession::query()->create([
        'id' => 'session-' . $minutesAgo . ($revoked ? '-revoked' : ''),
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Test/1.0',
        'last_used_at' => $moment,
        'created_at' => $moment,
        'revoked_at' => $revoked ? $moment : null,
    ]);
}

/**
 * `$this->artisan()` is typed `PendingCommand|int`, so it needs narrowing before anything can be
 * asserted on it. Once, here, rather than the same annotation at five call sites.
 */
function prune(TestCase $test): PendingCommand
{
    $run = $test->artisan('kern:identity:prune-sessions');

    /** @var PendingCommand $run */
    return $run;
}

/**
 * Nothing closes a `user_sessions` row when the session behind it simply times out — only signing
 * out and ending a device do. So the table gained a row per sign-in for ever, and the device list
 * reported every one of them as a device still signed in: 179 of them, on an account with two.
 */
it('removes the rows whose session has expired', function (): void {
    $user = User::factory()->create();

    ledgerRow($user, 1_000);

    prune($this)->assertSuccessful()->run();

    expect(UserSession::query()->count())->toBe(0);
});

it('leaves a session that is still live', function (): void {
    $user = User::factory()->create();

    ledgerRow($user, 5);

    prune($this)->assertSuccessful()->run();

    expect(UserSession::query()->count())->toBe(1);
});

/**
 * The margin is the whole reason the cutoff is not `session.lifetime` exactly.
 *
 * The containers do not share a clock, and a lifetime lengthened today leaves rows behind that were
 * written under the old one. Deleting a row for a session that is in fact still in use takes away the
 * owner's only way to end that device, so the cutoff errs late by construction.
 */
it('keeps a just-expired session for the length of the grace', function (): void {
    $user = User::factory()->create();

    // Past the 120-minute lifetime, inside the 60-minute grace.
    ledgerRow($user, 150);

    prune($this)->assertSuccessful()->run();

    expect(UserSession::query()->count())->toBe(1);
});

/**
 * A session in use writes its row only every few minutes (`SessionRegistrar::TOUCH_INTERVAL_MINUTES`),
 * so its row can be that stale while it is live — with no grace at all, too.
 */
it('allows for how stale a session in use lets its row run, even with no grace', function (): void {
    Config::set('identity.sessions.prune_grace_minutes', 0);
    $user = User::factory()->create();

    // Past the lifetime by less than the interval: possibly used a moment ago, and kept.
    ledgerRow($user, 120 + SessionRegistrar::TOUCH_INTERVAL_MINUTES - 1);
    // Past both: gone.
    ledgerRow($user, 120 + SessionRegistrar::TOUCH_INTERVAL_MINUTES + 1);

    prune($this)->assertSuccessful()->run();

    expect(UserSession::query()->pluck('id')->all())->toBe(['session-' . (120 + SessionRegistrar::TOUCH_INTERVAL_MINUTES - 1)]);
});

it('removes a revoked row too, once it is past the cutoff', function (): void {
    $user = User::factory()->create();

    ledgerRow($user, 1_000, revoked: true);

    prune($this)->assertSuccessful()->run();

    expect(UserSession::query()->count())->toBe(0);
});

/**
 * A guessed cutoff is a cutoff that may be too short, and too short deletes live rows. Failing is
 * visible in the run ledger; guessing is not.
 */
it('refuses to run rather than guess a cutoff', function (): void {
    $user = User::factory()->create();

    ledgerRow($user, 100_000);
    Config::set('session.lifetime', null);

    prune($this)->assertFailed()->run();

    expect(UserSession::query()->count())->toBe(1);
});
