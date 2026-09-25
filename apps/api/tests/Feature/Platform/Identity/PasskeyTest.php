<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\PasskeyTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserPasskey;
use Tests\Fakes\FakeWebauthnAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * `BLUEPRINT.md` §4.7's passkey/WebAuthn support. `Tests\Fakes\FakeWebauthnAuthenticator` is a real
 * ECDSA P-256 key pair producing genuinely valid, cryptographically signed WebAuthn responses —
 * `Webauthn\AuthenticatorAttestationResponseValidator`/`AuthenticatorAssertionResponseValidator`
 * run for real against its output, nothing here is mocked out.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * The origin `Kern\Platform\Identity\Support\Webauthn\PasskeyManager` will actually accept — derived
 * from `config('webauthn.allowed_origins')` rather than hardcoded, since that config defaults to
 * `APP_URL` (`config/webauthn.php`'s own docblock), which varies by environment (e.g. this
 * project's own `.env.example` ships `http://localhost:8000`, not bare `http://localhost`).
 */
function origin(): string
{
    /** @var list<string> $origins */
    $origins = config('webauthn.allowed_origins', ['http://localhost']);

    return $origins[0] ?? 'http://localhost';
}

function registerAndLogin(TestCase $test, string $email = 'ion@example.com', string $password = 'correct-horse-battery-staple'): User
{
    $user = User::factory()->withEmail($email)->create();
    $user->password_hash = Hash::make($password);
    $user->save();

    carryCookiesFrom($test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk());

    return $user;
}

it('registers a passkey for the authenticated user', function (): void {
    registerAndLogin($this);
    $authenticator = new FakeWebauthnAuthenticator();

    $optionsJson = carryCookiesFrom($this->postJson('/api/v1/auth/passkeys/registration/options')->assertOk())->json();

    $creationOptions = app(\Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer::class)
        ->get()
        ->deserialize(json_encode($optionsJson, JSON_THROW_ON_ERROR), PublicKeyCredentialCreationOptions::class, 'json');

    $credential = $authenticator->register($creationOptions, origin());

    $response = carryCookiesFrom($this->postJson('/api/v1/auth/passkeys/registration', [
        'credential' => $credential,
        'name' => 'My test authenticator',
    ]));

    $response->assertCreated();

    $passkey = UserPasskey::query()->sole();
    expect($passkey->name)->toBe('My test authenticator');
    expect($passkey->sign_count)->toBe(0);
});

it('logs in passwordlessly with a registered passkey', function (): void {
    $user = registerAndLogin($this);
    $authenticator = new FakeWebauthnAuthenticator();

    $creationOptionsJson = carryCookiesFrom($this->postJson('/api/v1/auth/passkeys/registration/options')->assertOk())->json();
    $creationOptions = app(\Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer::class)
        ->get()
        ->deserialize(json_encode($creationOptionsJson, JSON_THROW_ON_ERROR), PublicKeyCredentialCreationOptions::class, 'json');
    carryCookiesFrom($this->postJson('/api/v1/auth/passkeys/registration', [
        'credential' => $authenticator->register($creationOptions, origin()),
    ]))->assertCreated();

    carryCookiesFrom($this->postJson('/api/v1/auth/logout')->assertOk());
    $this->assertGuest();

    $requestOptionsJson = $this->postJson('/api/v1/auth/passkeys/login/options', ['email' => 'ion@example.com'])
        ->assertOk()->json();
    $requestOptions = app(\Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer::class)
        ->get()
        ->deserialize(json_encode($requestOptionsJson, JSON_THROW_ON_ERROR), PublicKeyCredentialRequestOptions::class, 'json');

    $assertion = $authenticator->assert($requestOptions, origin(), $user->id);

    $response = carryCookiesFrom($this->postJson('/api/v1/auth/passkeys/login', [
        'email' => 'ion@example.com',
        'credential' => $assertion,
    ]));

    $response->assertOk();
    $this->assertAuthenticated();

    $passkey = UserPasskey::query()->sole();
    expect($passkey->sign_count)->toBe(1);
    expect($passkey->last_used_at)->not->toBeNull();
});

it('returns valid-shaped (but useless) options for an email with no registered passkeys, without revealing whether the account exists', function (): void {
    $unknown = $this->postJson('/api/v1/auth/passkeys/login/options', ['email' => 'nobody@example.com'])->assertOk();
    registerAndLogin($this, 'has-account@example.com');

    $known = $this->postJson('/api/v1/auth/passkeys/login/options', ['email' => 'has-account@example.com'])->assertOk();

    expect($unknown->json('allowCredentials'))->toBe([]);
    expect($known->json('allowCredentials'))->toBe([]); // has no registered passkey either
    expect($unknown->json('rp'))->toBe($known->json('rp'));
});

it('rejects an assertion from a credential that was never registered', function (): void {
    $user = registerAndLogin($this);
    carryCookiesFrom($this->postJson('/api/v1/auth/logout')->assertOk());
    $authenticator = new FakeWebauthnAuthenticator();

    $requestOptionsJson = $this->postJson('/api/v1/auth/passkeys/login/options', ['email' => 'ion@example.com'])
        ->assertOk()->json();
    $requestOptions = app(\Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer::class)
        ->get()
        ->deserialize(json_encode($requestOptionsJson, JSON_THROW_ON_ERROR), PublicKeyCredentialRequestOptions::class, 'json');

    // This authenticator's credential was never registered against this (or any) account.
    $assertion = $authenticator->assert($requestOptions, origin(), $user->id);

    $this->postJson('/api/v1/auth/passkeys/login', [
        'email' => 'ion@example.com',
        'credential' => $assertion,
    ])->assertStatus(422);

    $this->assertGuest();
});
