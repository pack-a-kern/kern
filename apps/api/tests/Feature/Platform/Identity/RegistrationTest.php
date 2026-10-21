<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\RegistrationTest;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kern\Platform\Identity\Jobs\RegisterAccount;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Notifications\RegistrationAttemptNotification;

/**
 * `POST /api/v1/auth/register` — account creation only (`BLUEPRINT.md` §5.1). Passwords are Argon2id
 * (§4.7); a `null` password is explicitly allowed (passkey-only accounts).
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('registers a new account with a hashed password', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'ion.popescu@example.com',
        'password' => 'a-very-strong-password',
    ]);

    $response->assertAccepted();

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

    $response->assertAccepted();

    $user = User::query()->whereEmail('passkey.only@example.com')->firstOrFail();

    expect($user->password_hash)->toBeNull();
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
        ])->assertAccepted();
    }

    $this->postJson('/api/v1/auth/register', [
        'email' => 'one-too-many@example.com',
    ])->assertStatus(429);
});

/*
 * The form answers every address alike, so it cannot be asked whether one has an account here. What
 * differs happens after the answer, in the queue: an account made, or its owner told.
 */

it('answers an address that has an account exactly as it answers a new one', function (): void {
    $new = $this->postJson('/api/v1/auth/register', [
        'email' => 'first@example.com',
        'password' => 'a-very-strong-password',
    ]);
    $taken = $this->postJson('/api/v1/auth/register', [
        'email' => 'first@example.com',
        'password' => 'another-strong-password',
    ]);

    expect($taken->status())->toBe($new->status())
        ->and($taken->getContent())->toBe($new->getContent());
});

it('leaves an account that exists as it was', function (): void {
    $this->postJson('/api/v1/auth/register', ['email' => 'owner@example.com', 'password' => 'the-owners-password']);
    $this->postJson('/api/v1/auth/register', ['email' => 'owner@example.com', 'password' => 'a-strangers-password']);

    $accounts = User::query()->whereEmail('owner@example.com')->get();

    expect($accounts)->toHaveCount(1)
        ->and(Hash::check('the-owners-password', (string) $accounts->first()?->password_hash))->toBeTrue();
});

it('tells the owner someone tried their address, once however often it is tried', function (): void {
    Notification::fake();
    $this->postJson('/api/v1/auth/register', ['email' => 'owner@example.com', 'password' => 'the-owners-password']);
    $owner = User::query()->whereEmail('owner@example.com')->firstOrFail();

    Notification::assertNotSentTo($owner, RegistrationAttemptNotification::class);

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/auth/register', ['email' => 'owner@example.com', 'password' => "attempt-number-{$attempt}"]);
    }

    Notification::assertSentToTimes($owner, RegistrationAttemptNotification::class, 1);
});

it('queues the work encrypted, with the password already hashed', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/auth/register', ['email' => 'queued@example.com', 'password' => 'a-very-strong-password']);

    expect(class_implements(RegisterAccount::class))->toHaveKey(ShouldBeEncrypted::class);
    Queue::assertPushed(RegisterAccount::class, static fn (RegisterAccount $job): bool => $job->email === 'queued@example.com'
        && $job->passwordHash !== 'a-very-strong-password'
        && Hash::check('a-very-strong-password', (string) $job->passwordHash));
});

it('writes the notice in Romanian and in English', function (): void {
    $mail = (new RegistrationAttemptNotification())->toMail(new User());
    $text = implode("\n", [$mail->subject, ...$mail->introLines]);

    foreach (['ro', 'en'] as $locale) {
        foreach (['subject', 'tried', 'if_you', 'if_not'] as $key) {
            $line = __('platform::registration.attempt.' . $key, [], $locale);

            expect($line)->not->toBe('platform::registration.attempt.' . $key)
                ->and($text)->toContain($line);
        }
    }
});
