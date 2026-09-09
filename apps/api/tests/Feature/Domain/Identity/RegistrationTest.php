<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Identity\RegistrationTest;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * `POST /api/v1/auth/register` — account creation only (`BLUEPRINT.md` §5.1). Passwords are Argon2id
 * (§4.7); a `null` password is explicitly allowed (passkey-only accounts).
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('registers a new account with a hashed password', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'ion.popescu@example.com',
        'password' => 'a-very-strong-password',
    ]);

    $response->assertCreated();

    $user = User::query()->whereEmail('ion.popescu@example.com')->firstOrFail();

    expect($user->password_hash)->not->toBeNull();
    expect($user->password_hash)->not->toBe('a-very-strong-password');
    expect(Hash::check('a-very-strong-password', (string) $user->password_hash))->toBeTrue();
    expect(str_starts_with((string) $user->password_hash, '$argon2id$'))->toBeTrue();
});

it('registers a passkey-only account with no password', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'passkey.only@example.com',
    ]);

    $response->assertCreated();

    $user = User::query()->whereEmail('passkey.only@example.com')->firstOrFail();

    expect($user->password_hash)->toBeNull();
});

it('rejects a duplicate email', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'email' => 'duplicate@example.com',
        'password' => 'a-very-strong-password',
    ])->assertCreated();

    $this->postJson('/api/v1/auth/register', [
        'email' => 'duplicate@example.com',
        'password' => 'another-strong-password',
    ])->assertStatus(422);
});

it('rejects a malformed email', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'email' => 'not-an-email',
    ])->assertStatus(422);
});

it('is rate limited per IP', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/register', [
            'email' => "user{$i}@example.com",
        ])->assertCreated();
    }

    $this->postJson('/api/v1/auth/register', [
        'email' => 'one-too-many@example.com',
    ])->assertStatus(429);
});
