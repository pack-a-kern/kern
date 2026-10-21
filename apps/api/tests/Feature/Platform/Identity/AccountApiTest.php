<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Models\User;

function userWithPassword(string $password): User
{
    return User::createWithEmail('holder-' . uniqid() . '@example.test', Hash::make($password));
}

/** `getKey()` is `mixed` to static analysis; the model declares a string key. */
function accountId(User $user): string
{
    $key = $user->getKey();

    return is_scalar($key) ? (string) $key : '';
}

it('returns the signed-in account and nothing about anyone else', function (): void {
    $user = userWithPassword('correct-horse-battery');

    $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($response->json('id'))->toBe(accountId($user))
        ->and($response->json('email'))->toBe($user->email_ciphertext)
        ->and($response->json('has_password'))->toBeTrue()
        ->and($response->json())->not->toHaveKey('password_hash')
        ->and($response->json())->not->toHaveKey('email_blind_index');
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->putJson('/api/v1/me/password', [])->assertUnauthorized();
});

it('changes the password when the current one is given', function (): void {
    $user = userWithPassword('correct-horse-battery');

    $this->actingAs($user)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery',
        'password' => 'a-much-longer-replacement',
        'password_confirmation' => 'a-much-longer-replacement',
    ])->assertOk();

    expect(Hash::check('a-much-longer-replacement', (string) $user->fresh()?->password_hash))->toBeTrue();
});

it('refuses to change the password without the current one', function (): void {
    // An authenticated session is not enough: a session left open on a shared machine is exactly
    // the case this guards, and without it whoever finds it can lock the owner out for good.
    $user = userWithPassword('correct-horse-battery');

    $this->actingAs($user)->putJson('/api/v1/me/password', [
        'current_password' => 'not-the-password',
        'password' => 'a-much-longer-replacement',
        'password_confirmation' => 'a-much-longer-replacement',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    expect(Hash::check('correct-horse-battery', (string) $user->fresh()?->password_hash))->toBeTrue();
});

it('throttles repeated wrong current-password guesses', function (): void {
    // The current-password check is a brute-force oracle for a non-rotating secret from a session
    // that may not be the owner's. Five wrong tries are answered, the sixth is turned away with 429.
    $user = userWithPassword('correct-horse-battery');

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => 'wrong-guess-' . $attempt,
            'password' => 'a-much-longer-replacement',
            'password_confirmation' => 'a-much-longer-replacement',
        ])->assertStatus(422);
    }

    $this->actingAs($user)->putJson('/api/v1/me/password', [
        'current_password' => 'wrong-again',
        'password' => 'a-much-longer-replacement',
        'password_confirmation' => 'a-much-longer-replacement',
    ])->assertStatus(429);
});

it('forgives the budget once the password is proved, so a fumble never locks the owner out', function (): void {
    $user = userWithPassword('correct-horse-battery');

    // A few fat-fingered tries, then the right one — which must still go through and clear the count.
    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => 'typo-' . $attempt,
            'password' => 'a-much-longer-replacement',
            'password_confirmation' => 'a-much-longer-replacement',
        ])->assertStatus(422);
    }

    $this->actingAs($user)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery',
        'password' => 'a-much-longer-replacement',
        'password_confirmation' => 'a-much-longer-replacement',
    ])->assertOk();
});

it('rejects a short or unconfirmed password', function (): void {
    $user = userWithPassword('correct-horse-battery');

    $this->actingAs($user)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $this->actingAs($user)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery',
        'password' => 'a-much-longer-replacement',
        'password_confirmation' => 'something-else-entirely',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('records the change in the audit chain without recording any password', function (): void {
    $user = userWithPassword('correct-horse-battery');

    $this->actingAs($user)->putJson('/api/v1/me/password', [
        'current_password' => 'correct-horse-battery',
        'password' => 'a-much-longer-replacement',
        'password_confirmation' => 'a-much-longer-replacement',
    ])->assertOk();

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'PASSWORD_CHANGED',
        'resource_type' => 'user',
        'resource_id' => accountId($user),
    ]);
});
