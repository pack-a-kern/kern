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

    // Registering a passkey is behind `verify-recent` (a session alone must not be able to add a
    // factor and bootstrap past step-up). A real client steps up with its password first; these
    // tests exercise the ceremony, not that gate, so mark the session verified here.
    verifySession();

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

/**
 * Registers `$authenticator` as a passkey of the signed-in account, then signs out — the state a
 * person is in at the login page with a passkey on their device.
 */
function registerPasskeyAndSignOut(TestCase $test, FakeWebauthnAuthenticator $authenticator): void
{
    $creationOptions = app(\Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer::class)
        ->get()
        ->deserialize(
            json_encode(carryCookiesFrom($test->postJson('/api/v1/auth/passkeys/registration/options')->assertOk())->json(), JSON_THROW_ON_ERROR),
            PublicKeyCredentialCreationOptions::class,
            'json',
        );
    carryCookiesFrom($test->postJson('/api/v1/auth/passkeys/registration', [
        'credential' => $authenticator->register($creationOptions, origin()),
    ]))->assertCreated();

    carryCookiesFrom($test->postJson('/api/v1/auth/logout')->assertOk());
    $test->assertGuest();
}

/** The login page asking for a challenge, in this browser's session — no account named. */
function loginOptions(TestCase $test): PublicKeyCredentialRequestOptions
{
    $json = carryCookiesFrom($test->postJson('/api/v1/auth/passkeys/login/options')->assertOk())->json();

    return app(\Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer::class)
        ->get()
        ->deserialize(json_encode($json, JSON_THROW_ON_ERROR), PublicKeyCredentialRequestOptions::class, 'json');
}

it('logs in with a passkey alone, naming no account', function (): void {
    $user = registerAndLogin($this);
    $authenticator = new FakeWebauthnAuthenticator();
    registerPasskeyAndSignOut($this, $authenticator);

    $options = loginOptions($this);
    expect($options->allowCredentials)->toBe([]);

    carryCookiesFrom($this->postJson('/api/v1/auth/passkeys/login', [
        'credential' => $authenticator->assert($options, origin(), $user->id),
    ]))->assertOk()->assertJson(['id' => $user->id]);

    $this->assertAuthenticatedAs($user);
    $passkey = UserPasskey::query()->sole();
    expect($passkey->sign_count)->toBe(1)
        ->and($passkey->last_used_at)->not->toBeNull();
});

it('answers the same options whoever asks, so they reveal no account', function (): void {
    $empty = $this->postJson('/api/v1/auth/passkeys/login/options')->assertOk();
    registerAndLogin($this);
    registerPasskeyAndSignOut($this, new FakeWebauthnAuthenticator());
    $withAccounts = $this->postJson('/api/v1/auth/passkeys/login/options')->assertOk();

    expect($empty->json('allowCredentials'))->toBe([])
        ->and($withAccounts->json('allowCredentials'))->toBe([])
        ->and($empty->collect()->keys()->all())->toBe($withAccounts->collect()->keys()->all())
        ->and($empty->json('rp'))->toBe($withAccounts->json('rp'));
});

it('refuses a passkey that was never registered', function (): void {
    $user = registerAndLogin($this);
    carryCookiesFrom($this->postJson('/api/v1/auth/logout')->assertOk());

    $this->postJson('/api/v1/auth/passkeys/login', [
        'credential' => (new FakeWebauthnAuthenticator())->assert(loginOptions($this), origin(), $user->id),
    ])->assertStatus(422);

    $this->assertGuest();
});

it('refuses a passkey presented as another account', function (): void {
    $victim = User::factory()->withEmail('victim@example.com')->create();
    registerAndLogin($this);
    $authenticator = new FakeWebauthnAuthenticator();
    registerPasskeyAndSignOut($this, $authenticator);

    // The attacker's own, valid passkey — with the victim's id where the device names its account.
    $this->postJson('/api/v1/auth/passkeys/login', [
        'credential' => $authenticator->assert(loginOptions($this), origin(), $victim->id),
    ])->assertStatus(422);

    $this->assertGuest();
});

it('refuses a challenge that another browser was given', function (): void {
    $user = registerAndLogin($this);
    $authenticator = new FakeWebauthnAuthenticator();
    registerPasskeyAndSignOut($this, $authenticator);
    $assertion = $authenticator->assert(loginOptions($this), origin(), $user->id);

    // Another browser: none of this one's cookies, so another session.
    $this->defaultCookies = [];
    $this->unencryptedCookies = [];

    $this->postJson('/api/v1/auth/passkeys/login', ['credential' => $assertion])->assertStatus(422);
    $this->assertGuest();
});

it('uses a challenge once', function (): void {
    $user = registerAndLogin($this);
    $authenticator = new FakeWebauthnAuthenticator();
    registerPasskeyAndSignOut($this, $authenticator);
    $options = loginOptions($this);

    carryCookiesFrom($this->postJson('/api/v1/auth/passkeys/login', [
        'credential' => $authenticator->assert($options, origin(), $user->id),
    ]))->assertOk();
    carryCookiesFrom($this->postJson('/api/v1/auth/logout')->assertOk());

    // The same signed answer again, and a fresh signature over the same, spent challenge.
    $this->postJson('/api/v1/auth/passkeys/login', [
        'credential' => $authenticator->assert($options, origin(), $user->id, 2),
    ])->assertStatus(422);
    $this->assertGuest();
});

it('refuses a suspended account its passkey', function (): void {
    $user = registerAndLogin($this);
    $authenticator = new FakeWebauthnAuthenticator();
    registerPasskeyAndSignOut($this, $authenticator);
    $user->forceFill(['status' => \Kern\Platform\Identity\Enums\UserStatus::SUSPENDED])->save();

    $this->postJson('/api/v1/auth/passkeys/login', [
        'credential' => $authenticator->assert(loginOptions($this), origin(), $user->id),
    ])->assertForbidden();
    $this->assertGuest();
});

it('limits how often an address may ask', function (): void {
    $limit = config('identity.rate_limits.passkey_ceremony_per_ip.max_attempts');
    expect($limit)->toBeInt();

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson('/api/v1/auth/passkeys/login/options')->assertOk();
    }

    $this->postJson('/api/v1/auth/passkeys/login/options')->assertStatus(429);
    $this->postJson('/api/v1/auth/passkeys/login', ['credential' => []])->assertStatus(429);
});

/*
 * The cache in production unserializes no PHP class (`config/cache.php` `serializable_classes`). The
 * `array` store these tests otherwise run on keeps values as they are, which is how every ceremony above
 * passed while no passkey had ever worked anywhere else. These two run against a store that serializes
 * and refuses classes, as Redis does there.
 */
function strictChallengeStore(): \Kern\Platform\Identity\Support\Webauthn\ChallengeStore
{
    config([
        'cache.stores.no-classes' => ['driver' => 'array', 'serialize' => true],
        'cache.serializable_classes' => false,
    ]);

    return new \Kern\Platform\Identity\Support\Webauthn\ChallengeStore(
        \Illuminate\Support\Facades\Cache::store('no-classes'),
        app(\Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer::class),
    );
}

it('keeps a challenge through a cache that unserializes no class, as production does', function (): void {
    $store = strictChallengeStore();

    $creation = PublicKeyCredentialCreationOptions::create(
        \Webauthn\PublicKeyCredentialRpEntity::create('Kern', 'localhost'),
        \Webauthn\PublicKeyCredentialUserEntity::create('ion', 'user-id', 'Ion'),
        random_bytes(32),
    );
    $request = PublicKeyCredentialRequestOptions::create(random_bytes(32), 'localhost');

    $store->putCreationOptions('user-id', $creation);
    $store->putRequestOptions('session-key', $request);

    expect($store->pullCreationOptions('user-id')->challenge)->toBe($creation->challenge)
        ->and($store->pullRequestOptions('session-key')->challenge)->toBe($request->challenge);

    // And the store this models really is the one that broke an object put in whole.
    \Illuminate\Support\Facades\Cache::store('no-classes')->put('whole', $creation);
    expect(\Illuminate\Support\Facades\Cache::store('no-classes')->get('whole'))->toBeInstanceOf(\__PHP_Incomplete_Class::class);
});

it('still uses a challenge once only, and refuses one that is not there', function (): void {
    $store = strictChallengeStore();
    $store->putRequestOptions('session-key', PublicKeyCredentialRequestOptions::create(random_bytes(32), 'localhost'));
    $store->pullRequestOptions('session-key');

    expect(fn () => $store->pullRequestOptions('session-key'))
        ->toThrow(\Kern\Platform\Identity\Support\Webauthn\Exceptions\ChallengeExpiredException::class);
});

it('asks the device to verify its owner and keep the key, and sends no empty member for the browser to ignore', function (): void {
    registerAndLogin($this);

    $creation = $this->postJson('/api/v1/auth/passkeys/registration/options')->assertOk();
    $request = $this->postJson('/api/v1/auth/passkeys/login/options')->assertOk();

    expect($creation->json('authenticatorSelection'))->toBe(['requireResidentKey' => true, 'userVerification' => 'required', 'residentKey' => 'required'])
        ->and($request->json('userVerification'))->toBe('required')
        ->and($creation->getContent() . $request->getContent())->not->toContain('null');
});
