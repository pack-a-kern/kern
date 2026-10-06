<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\StepUpVerificationTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Enums\MfaMethodType;
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
    bootstrapIdentityCryptoForTests();
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
        ->assertStatus(422);

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
