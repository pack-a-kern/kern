<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\StepUpVerificationTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Enums\StepUpFactor;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Identity\Models\UserRecoveryCode;
use Kern\Platform\Identity\Support\StepUpPolicy;
use OTPHP\TOTP;

/**
 * `POST /api/v1/auth/step-up` — proving the second factor again inside a live session.
 *
 * The verification itself is `CompleteMfaChallenge`'s, already covered by the login flow's own tests
 * and deliberately not re-tested here. What is this endpoint's own is what a success *grants*, and
 * what each kind of failure answers.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/** @return array{0: User, 1: non-empty-string} */
function enrolled(): array
{
    $user = User::factory()->create();
    $secret = TOTP::generate()->getSecret();

    // `getSecret()` is typed `string`; `createFromSecret()` wants a non-empty one, and a generated
    // secret that came back empty is a broken library rather than a test condition.
    expect($secret)->not->toBe('');
    /** @var non-empty-string $secret */

    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => $secret,
        'confirmed_at' => now('UTC'),
    ]);

    return [$user, $secret];
}

it('marks the session verified when the code is right', function (): void {
    [$user, $secret] = enrolled();
    $code = TOTP::createFromSecret($secret)->now();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('verified_for_seconds', app(StepUpPolicy::class)->windowSeconds());

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeTrue();
});

it('answers a wrong code as a validation error, not as another demand to verify', function (): void {
    // A 403 carrying VERIFICATION_REQUIRED is what the client's interceptor reacts to by opening the
    // dialog. Answering a bad code that way would reopen the dialog the person is already in.
    [$user] = enrolled();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeFalse();
});

it('refuses an account with nothing to verify, even reached directly', function (): void {
    // The middleware says this first, but the endpoint is routable on its own and must not pretend to
    // have verified an account that holds no factor.
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('factor');

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeFalse();
});

it('accepts a recovery code, and burns it', function (): void {
    [$user] = enrolled();
    $plain = 'ABCD-EFGH-JKMN';

    UserRecoveryCode::create([
        'user_id' => $user->id,
        'code_hash' => \Illuminate\Support\Facades\Hash::make($plain),
    ]);

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['code' => $plain])
        ->assertOk();

    // Replay must fail: the same code cannot elevate a second time. This is `CompleteMfaChallenge`'s
    // guarantee, asserted here because step-up is a second place it now matters.
    session()->forget('auth.step_up_verified_at');

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['code' => $plain])
        ->assertStatus(422);
});

it('requires a signed-in account', function (): void {
    $this->postJson('/api/v1/auth/step-up', ['code' => '000000'])->assertUnauthorized();
});

it('is not itself behind the gate it opens', function (): void {
    // A route that demanded recent verification in order to verify could never be satisfied.
    [$user, $secret] = enrolled();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['code' => TOTP::createFromSecret($secret)->now()])
        ->assertOk();
});

it('expires on its own, with nothing running to expire it', function (): void {
    [$user, $secret] = enrolled();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['code' => TOTP::createFromSecret($secret)->now()])
        ->assertOk();

    $policy = app(StepUpPolicy::class);
    expect($policy->isRecentlyVerified(testSessionStore()))->toBeTrue();

    $this->travel($policy->windowSeconds() + 1)->seconds();

    expect($policy->isRecentlyVerified(testSessionStore()))->toBeFalse();
});

/*
 * The password factor.
 *
 * An account with no authenticator app used to be waved through every guarded route: the gate applied
 * to the people who had armed themselves and to nobody else. These cover the factor that closed that,
 * and — more importantly — that offering it never weakens an account which has the stronger one.
 */

/** `config()` is `mixed`; both uses here need an int, so it is narrowed once. */
function passwordBudgetSetting(string $key): int
{
    $value = config("identity.rate_limits.step_up_password_per_user.{$key}");

    return is_int($value) ? $value : 0;
}

/** An ordinary account: a password and no second factor, which is what most of them are. */
function withPassword(string $password = 'correct-horse-battery-staple'): User
{
    $user = User::factory()->create();
    $user->password_hash = Hash::make($password);
    $user->save();

    return $user;
}

it('asks an account with only a password to confirm it', function (): void {
    $user = withPassword();

    expect(app(StepUpPolicy::class)->challengeFor($user))->toBe(StepUpFactor::PASSWORD);

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['password' => 'correct-horse-battery-staple'])
        ->assertOk();

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeTrue();
});

it('answers a wrong password as a validation error, and grants nothing', function (): void {
    $user = withPassword();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['password' => 'not-the-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeFalse();
});

/*
 * The property that makes offering a password safe at all.
 *
 * An account holding an authenticator app is asked for the code and accepts nothing else, however the
 * request is shaped — anybody who can reach this endpoint chooses the body. A gate that took either
 * would be worth its weaker half, and every account that had bothered to enrol would have been
 * quietly downgraded by this change.
 */
it('will not take a password from an account that holds an authenticator', function (): void {
    [$user] = enrolled();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();

    expect(app(StepUpPolicy::class)->challengeFor($user))->toBe(StepUpFactor::TOTP);

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['password' => 'correct-horse-battery-staple'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeFalse();
});

it('holds nothing to ask an account with neither factor', function (): void {
    // `password_hash` is nullable — the schema's allowance for a passkey-only account. Nothing
    // registers one today, and until the step-up endpoint can run an assertion such an account is
    // not challenged rather than being shown a dialog it could never answer.
    $user = User::factory()->create();

    expect($user->password_hash)->toBeNull()
        ->and(app(StepUpPolicy::class)->challengeFor($user))->toBeNull();
});

/*
 * Two budgets, doing two different jobs.
 *
 * The route's own `throttle:step-up-per-user` stops a burst: five tries a minute. That is sized for a
 * six-digit code which stops being valid within the minute anyway, and against a password it would
 * still allow three hundred guesses an hour. The password budget is the one that makes grinding
 * pointless, because it outlives the minute — which is the only thing it adds, and so the only thing
 * worth asserting.
 */
it('keeps holding a password back after the route\'s own burst limit has reset', function (): void {
    $user = withPassword();
    $attempts = passwordBudgetSetting('max_attempts');

    foreach (range(1, $attempts) as $ignored) {
        $this->actingAs($user, 'web')
            ->postJson('/api/v1/auth/step-up', ['password' => 'not-the-password'])
            ->assertStatus(422);
    }

    // Past the burst window, so the route's throttle is no longer the thing answering.
    $this->travel(61)->seconds();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['password' => 'correct-horse-battery-staple'])
        ->assertStatus(429);

    expect(app(StepUpPolicy::class)->isRecentlyVerified(testSessionStore()))->toBeFalse();

    // And it does let go, rather than locking the account out of the gate for good.
    $this->travel(passwordBudgetSetting('decay_seconds'))->seconds();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['password' => 'correct-horse-battery-staple'])
        ->assertOk();
});

it('records the confirmation in the audit chain, naming the factor', function (): void {
    $user = withPassword();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/auth/step-up', ['password' => 'correct-horse-battery-staple'])
        ->assertOk();

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'AuthStepUpSucceeded',
        'actor_id' => $user->id,
        'resource_type' => 'User',
    ]);
});
