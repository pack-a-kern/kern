<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Identity\EmailVerificationTest;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Actions\ConfirmEmailVerification;
use Kern\Platform\Identity\Actions\RequestEmailVerification;
use Kern\Platform\Identity\Contracts\VerificationSender;
use Kern\Platform\Identity\Enums\VerificationChannel;
use Kern\Platform\Identity\Exceptions\InvalidVerificationCodeException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserVerificationChallenge;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use RuntimeException;

/**
 * Where the fake sender leaves the code it was asked to deliver.
 *
 * A class of its own rather than a static on the anonymous sender: `app(VerificationSender::class)`
 * resolves to the *bound* implementation as far as static analysis is concerned, and reading a
 * property off that is a property the interface never promised.
 */
final class CapturedCode
{
    public static ?string $value = null;
}

/**
 * The gate, and the way through it.
 *
 * Most of these are about not stranding somebody. A verification screen is easy to build and easy to
 * build into a dead end — the screen itself, the request it makes to draw itself, the resend, the
 * submit and the way out all have to stay reachable while everything else is closed, and each of
 * those is a separate chance to get it wrong.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    // A sender that keeps the code instead of mailing it. The real one decrypts an address and hands
    // the code to Laravel's mailer; neither is what these tests are about, and a fake here is what
    // lets them assert on the code itself.
    CapturedCode::$value = null;

    app()->instance(VerificationSender::class, new class () implements VerificationSender {
        public function channel(): VerificationChannel
        {
            return VerificationChannel::EMAIL;
        }

        public function canReach(User $user): bool
        {
            return is_string($user->email_ciphertext) && $user->email_ciphertext !== '';
        }

        public function destination(User $user): string
        {
            return Normalizer::email((string) $user->email_ciphertext);
        }

        public function send(User $user, string $code, int $ttlSeconds): void
        {
            CapturedCode::$value = $code;
        }
    });
});

function requireVerification(bool $required = true): void
{
    app(SettingsRepository::class)->set(
        SettingKey::IDENTITY_EMAIL_VERIFICATION_REQUIRED,
        $required ? 'true' : 'false',
        null,
    );
}

const PASSWORD = 'correct-horse-battery-staple';

function unverifiedUser(): User
{
    return User::createWithEmail('unverified-' . uniqid() . '@example.test', Hash::make(PASSWORD));
}

/**
 * Signs in through the endpoint rather than with `actingAs`.
 *
 * `actingAs` marks the request authenticated without starting a session, and every write here is a
 * session-bearing POST in the `web` group — logging out reads the session id, and the CSRF layer wants
 * a session to have been started at all. Going through `POST /auth/login` is also the more honest
 * test: it proves an unverified account can still sign in, which it must, or nobody could ever reach
 * the screen that lets them verify.
 */
function signIn(User $user): void
{
    /**
     * Pest's own return type for `test()` describes its use immediately after `it()`; called from
     * inside a running test's body — as here — it hands back that test's TestCase instead, which is
     * why this cannot be expressed as a plain narrowing. `tests/Pest.php`'s `carryCookiesFrom()` does
     * the identical thing for the identical reason.
     *
     * @var \Tests\TestCase $test
     *
     * @phpstan-ignore varTag.nativeType
     */
    $test = test();

    $test->postJson('/api/v1/auth/login', [
        'email' => (string) $user->email_ciphertext,
        'password' => PASSWORD,
    ])->assertOk();
}

/** The code the fake sender captured on the last send. */
function sentCode(): string
{
    return CapturedCode::$value ?? throw new RuntimeException('No code was sent.');
}

it('lets an unverified account through while the setting is off', function (): void {
    requireVerification(false);

    signIn(unverifiedUser());

    $this->get('/dashboard')->assertOk();
});

it('holds an unverified account at the verification screen once the setting is on', function (): void {
    requireVerification();

    signIn(unverifiedUser());

    $this->get('/dashboard')->assertRedirect('/verify-email');
});

it('keeps the verification screen itself reachable, or there would be nowhere to send anybody', function (): void {
    requireVerification();

    signIn(unverifiedUser());

    $this->get('/verify-email')->assertOk();
});

it('keeps the request the screen draws itself with reachable', function (): void {
    requireVerification();

    // The subtle version of the dead end: the page loads and then cannot say anything about why the
    // person is on it, because the one endpoint it needs is behind the gate that put them there.
    signIn(unverifiedUser());

    $this->getJson('/api/v1/auth/email/verification')
        ->assertOk()
        ->assertJson(['verified' => false, 'required' => true, 'deliverable' => true]);
});

it('keeps the way out reachable', function (): void {
    requireVerification();

    signIn(unverifiedUser());

    $this->postJson('/api/v1/auth/logout')->assertOk();
});

it('closes every other endpoint with a code a caller can read', function (): void {
    requireVerification();

    signIn(unverifiedUser());

    $this->getJson('/api/v1/me')
        ->assertStatus(409)
        ->assertJson(['code' => 'EMAIL_NOT_VERIFIED']);
});

it('lets a verified account through', function (): void {
    requireVerification();

    $user = unverifiedUser();
    $user->email_verified_at = Carbon::now('UTC');
    $user->save();

    signIn($user);

    $this->get('/dashboard')->assertOk();
});

it('verifies an address with the code it sent, and opens the application', function (): void {
    requireVerification();
    $user = unverifiedUser();

    signIn($user);

    $this->postJson('/api/v1/auth/email/resend')->assertOk()->assertJson(['sent' => true]);

    $this->postJson('/api/v1/auth/email/verify', ['code' => sentCode()])
        ->assertOk()
        ->assertJson(['verified' => true]);

    expect($user->fresh()?->email_verified_at)->not->toBeNull();

    $this->get('/dashboard')->assertOk();
});

it('refuses a wrong code against the field it was typed into', function (): void {
    requireVerification();
    $user = unverifiedUser();

    signIn($user);

    $this->postJson('/api/v1/auth/email/resend')->assertOk();

    $this->postJson('/api/v1/auth/email/verify', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect($user->fresh()?->email_verified_at)->toBeNull();
});

it('will not accept the same code twice', function (): void {
    requireVerification();
    $user = unverifiedUser();

    $challenge = app(RequestEmailVerification::class)($user);
    $code = sentCode();

    app(ConfirmEmailVerification::class)($user, $code);

    expect($challenge->fresh()?->consumed_at)->not->toBeNull();

    /*
     * Through the action rather than the endpoint, deliberately.
     *
     * The invariant is about the challenge: a consumed one is gone, whatever the account's state. The
     * endpoint cannot show that, because it answers "already verified" before it looks at any code —
     * and unverifying the account between the two requests does not help either: `Auth`'s guard is a
     * container singleton that keeps its in-memory user across calls within one test, which
     * `tests/Pest.php` warns about by name. So the request would keep seeing the verified copy and
     * answer from the shortcut, and the test would pass without ever reaching what it claims to check.
     */
    $user->email_verified_at = null;
    $user->save();

    expect(fn () => app(ConfirmEmailVerification::class)($user, $code))
        ->toThrow(InvalidVerificationCodeException::class);
});

it('will not accept an expired code', function (): void {
    requireVerification();
    $user = unverifiedUser();

    signIn($user);

    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $code = sentCode();

    UserVerificationChallenge::query()
        ->where('user_id', $user->id)
        ->update(['expires_at' => Carbon::now('UTC')->subSecond()]);

    $this->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertStatus(422);
});

it('stops accepting guesses once the attempt ceiling is reached', function (): void {
    requireVerification();
    $user = unverifiedUser();

    signIn($user);

    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $code = sentCode();

    UserVerificationChallenge::query()
        ->where('user_id', $user->id)
        ->update(['attempts' => config()->integer('identity.verification.max_attempts')]);

    // The right code, and it is refused: the ceiling is a column, so flushing the rate limiter's cache
    // does not hand anybody a fresh budget.
    $this->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertStatus(422);
});

it('stores the code as a hash and never in the clear', function (): void {
    requireVerification();
    $user = unverifiedUser();

    signIn($user);

    $this->postJson('/api/v1/auth/email/resend')->assertOk();

    $challenge = UserVerificationChallenge::query()->where('user_id', $user->id)->firstOrFail();

    expect($challenge->code_hash)->not->toBe(sentCode())
        ->and(Hash::check(sentCode(), $challenge->code_hash))->toBeTrue();
});

it('can be verified from the shell when mail is broken', function (): void {
    requireVerification();
    $user = unverifiedUser();
    $email = (string) $user->email_ciphertext;

    // The door that is not behind the gate. Without it, a deployment with this setting on and mail
    // misconfigured is one nobody can enter — including whoever would fix the mail settings.
    expect(Artisan::call('kern:identity:verify-email', ['email' => $email]))->toBe(0)
        ->and($user->fresh()?->email_verified_at)->not->toBeNull();

    expect(Artisan::call('kern:identity:verify-email', ['email' => $email, '--undo' => true]))->toBe(0)
        ->and($user->fresh()?->email_verified_at)->toBeNull();
});
