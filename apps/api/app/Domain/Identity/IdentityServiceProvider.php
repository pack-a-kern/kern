<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Support\Webauthn\ChallengeStore;
use App\Domain\Identity\Support\Webauthn\PasskeyManager;
use App\Domain\Identity\Support\Webauthn\WebauthnCredentialRepository;
use App\Domain\Identity\Support\Webauthn\WebauthnSerializer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;

/**
 * Wires the WebAuthn support classes (`Support\Webauthn\*`, all stateless or request-scoped-safe —
 * see their own docblocks) as singletons, and registers every named rate limiter this module's
 * routes attach via `throttle:<name>` middleware.
 *
 * ## How rate limiting is keyed (`BLUEPRINT.md` §4.7 / `docs/AGENTS.md` §8)
 *
 * `login-per-account-ip` is keyed on `{email blind index}|{ip}` — the blind index, never the raw
 * email, so a rate-limiter cache key never carries plaintext PII, and it is already exactly the
 * same HMAC used for the login lookup itself (`App\Domain\Identity\Models\User::scopeWhereEmail`),
 * so no new secret is introduced. Keying on the *pair* rather than the email alone is what keeps
 * one user's attempts from locking out another's account: an attacker spamming failed attempts
 * against a victim's email from many different IPs never accumulates against a single key (each
 * IP gets its own bucket for that account), and `login-per-ip` (keyed on IP alone) independently
 * caps how many accounts a single IP can probe regardless of which emails it tries. Both
 * middleware are attached together on the login route — see `routes/api.php`.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebauthnSerializer::class);
        $this->app->singleton(ChallengeStore::class);
        $this->app->singleton(WebauthnCredentialRepository::class);
        $this->app->singleton(PasskeyManager::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiter('login-per-account-ip', 'login_per_account_ip', function (Request $request): string {
            $email = $request->string('email')->toString();
            $index = $email === ''
                ? 'no-email'
                : app(BlindIndexHasher::class)->compute(Normalizer::email($email));

            return $index . '|' . $request->ip();
        });

        $this->registerRateLimiter('login-per-ip', 'login_per_ip', static fn (Request $request): string => (string) $request->ip());

        $this->registerRateLimiter('mfa-challenge-per-user', 'mfa_challenge_per_user', static function (Request $request): string {
            $user = $request->user();
            $identifier = $user?->getAuthIdentifier();
            $identifierString = is_string($identifier) ? $identifier : null;

            return $identifierString !== null
                ? $identifierString . '|' . $request->ip()
                : (string) $request->ip();
        });

        $this->registerRateLimiter('passkey-ceremony-per-ip', 'passkey_ceremony_per_ip', static fn (Request $request): string => (string) $request->ip());

        $this->registerRateLimiter('register-per-ip', 'register_per_ip', static fn (Request $request): string => (string) $request->ip());
    }

    private function registerRateLimiter(string $name, string $configKey, callable $keyResolver): void
    {
        RateLimiter::for($name, function (Request $request) use ($configKey, $keyResolver): Limit {
            $maxAttempts = config("identity.rate_limits.{$configKey}.max_attempts");
            $decaySeconds = config("identity.rate_limits.{$configKey}.decay_seconds");

            return new Limit(
                $keyResolver($request),
                is_int($maxAttempts) ? $maxAttempts : 5,
                is_int($decaySeconds) ? $decaySeconds : 60,
            );
        });
    }
}
