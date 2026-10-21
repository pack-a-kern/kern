<?php

declare(strict_types=1);

namespace Kern\Platform\Identity;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Access\Services\AccessCatalogue;
use Kern\Platform\Console\Commands\Access\GrantPlatformRoleCommand;
use Kern\Platform\Console\Commands\Access\SyncAccessCatalogueCommand;
use Kern\Platform\Console\Commands\Identity\CreateAdministratorCommand;
use Kern\Platform\Console\Commands\Identity\PruneExpiredSessionsCommand;
use Kern\Platform\Console\Commands\Identity\VerifyUserEmailCommand;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\BlindIndexReindexers;
use Kern\Platform\Crypto\BlindIndex\EloquentColumnReindexer;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Avatars\AvatarStore;
use Kern\Platform\Identity\Avatars\SetAvatar;
use Kern\Platform\Identity\Contracts\VerificationSender;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\PendingMfaLogin;
use Kern\Platform\Identity\Support\Verification\MailVerificationSender;
use Kern\Platform\Identity\Support\Webauthn\ChallengeStore;
use Kern\Platform\Identity\Support\Webauthn\PasskeyManager;
use Kern\Platform\Identity\Support\Webauthn\WebauthnCredentialRepository;
use Kern\Platform\Identity\Support\Webauthn\WebauthnSerializer;
use Kern\Platform\Routing\ConfiguredRateLimit;
use Kern\Platform\Routing\PackageRoutes;
use Kern\Platform\Uploads\Contracts\UploadScanner;
use Kern\Platform\Uploads\RefusingUploadScanner;
use Kern\Platform\Uploads\SquareImageNormaliser;

/**
 * Wires the WebAuthn support classes (`Support\Webauthn\*`, all stateless or request-scoped-safe —
 * see their own docblocks) as singletons, and registers every named rate limiter this module's
 * routes attach via `throttle:<name>` middleware.
 *
 * ## How rate limiting is keyed (`BLUEPRINT.md` §4.7 / `docs/AGENTS.md` §8)
 *
 * `login-per-account-ip` is keyed on `{email blind index}|{ip}` — the blind index, never the raw
 * email, so a rate-limiter cache key never carries plaintext PII, and it is already exactly the
 * same HMAC used for the login lookup itself (`Kern\Platform\Identity\Models\User::scopeWhereEmail`),
 * so no new secret is introduced. Keying on the *pair* rather than the email alone is what keeps
 * one user's attempts from locking out another's account: an attacker spamming failed attempts
 * against a victim's email from many different IPs never accumulates against a single key (each
 * IP gets its own bucket for that account), and `login-per-ip` (keyed on IP alone) independently
 * caps how many accounts a single IP can probe regardless of which emails it tries. Both
 * middleware are attached together on the login route — see `routes/api.php`.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    /** The container key the tagged reindexer is bound under; a name, not a class, because it is one. */
    private const string USERS_EMAIL_REINDEXER = 'kern.reindexer.users-email';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/identity.php', 'identity');
        $this->mergeConfigFrom(__DIR__ . '/../../config/webauthn.php', 'webauthn');
        $this->mergeConfigFrom(__DIR__ . '/../../config/access.php', 'access');
        $this->mergeConfigFrom(__DIR__ . '/../../config/avatars.php', 'avatars');

        $this->app->singleton(AccessCatalogue::class);
        $this->app->singleton(WebauthnSerializer::class);
        $this->app->singleton(ChallengeStore::class);
        $this->app->singleton(WebauthnCredentialRepository::class);
        $this->app->singleton(PasskeyManager::class);

        $this->registerAvatars();
        $this->registerBlindIndexReindexer();

        /*
         * One channel today, bound as the channel. `Contracts\VerificationSender` is the seam a second
         * one arrives through — see `Enums\VerificationChannel` — and binding the interface rather
         * than type-hinting the class is what makes that a one-line change here instead of a change in
         * every caller.
         */
        $this->app->bind(VerificationSender::class, MailVerificationSender::class);
    }

    /**
     * `users.email_blind_index`, the one blind-indexed column this package owns.
     *
     * Registered as a tagged closure rather than a class of its own: what it takes to recompute the
     * column is the email, and `Kern\Platform\Crypto\BlindIndex\EloquentColumnReindexer` already
     * knows how to walk a table doing that. A row with no ciphertext has no email to hash — an erased
     * account, whose blind index is deliberately a hash of a UUID and belongs to no address any more
     * (`Actions\EraseUser`) — so it is skipped rather than counted as a failure to recompute.
     */
    /**
     * The avatar pipeline: a scanner, an image rebuilder, and somewhere to put the result.
     *
     * {@see UploadScanner} falls back to {@see RefusingUploadScanner}, which refuses everything. That is
     * the safe default rather than the useful one: `kern/documents` binds it to the adapter in front of
     * clamd, and an installation without that package — or with it misconfigured — cannot accept uploads
     * instead of accepting unscanned ones.
     *
     * **`bindIf`, not `bind`, and the difference is the whole guarantee.** Both providers are discovered
     * from their packages, and Composer registers `kern/documents` before `kern/platform`, so a plain
     * `bind` here overwrites the real scanner with the refusing one — which was the first thing this did,
     * and it failed closed loudly enough to be caught. `bindIf` leaves a binding that already exists
     * alone, so the answer is the same whichever provider runs first.
     */
    private function registerAvatars(): void
    {
        $this->app->bindIf(UploadScanner::class, RefusingUploadScanner::class);

        $this->app->singleton(SquareImageNormaliser::class, static function (Application $app): SquareImageNormaliser {
            $config = $app->make(ConfigRepository::class);

            return new SquareImageNormaliser(
                $config->integer('avatars.side'),
                $config->integer('avatars.max_side'),
                $config->integer('avatars.quality'),
            );
        });

        $this->app->singleton(AvatarStore::class, static function (Application $app): AvatarStore {
            $config = $app->make(ConfigRepository::class);

            return new AvatarStore(
                $app->make(FilesystemFactory::class)->disk($config->string('avatars.disk')),
            );
        });

        $this->app->bind(SetAvatar::class, static function (Application $app): SetAvatar {
            $config = $app->make(ConfigRepository::class);

            return new SetAvatar(
                $app->make(UploadScanner::class),
                $app->make(SquareImageNormaliser::class),
                $app->make(AvatarStore::class),
                $app->make(AuditChainService::class),
                $config->integer('avatars.max_bytes'),
            );
        });
    }

    private function registerBlindIndexReindexer(): void
    {
        $this->app->bind(self::USERS_EMAIL_REINDEXER, static function (Application $app): EloquentColumnReindexer {
            return new EloquentColumnReindexer(
                label: 'users.email_blind_index',
                query: static fn (): Builder => User::query()
                    ->whereNotNull('email_ciphertext')
                    ->where('status', '!=', UserStatus::DELETED->value),
                indexColumn: 'email_blind_index',
                normalize: static function (Model $row): ?string {
                    $email = $row->getAttribute('email_ciphertext');

                    return is_string($email) && $email !== '' ? Normalizer::email($email) : null;
                },
                hasher: $app->make(BlindIndexHasher::class),
            );
        });

        $this->app->tag([self::USERS_EMAIL_REINDEXER], BlindIndexReindexers::TAG);
    }

    public function boot(): void
    {
        PackageRoutes::load(__DIR__ . '/../../routes/identity.php');
        // Words this package writes outside the application — mail — where the interface's
        // dictionaries cannot reach. `platform::registration.*` and so on.
        $this->loadTranslationsFrom(__DIR__ . '/../../lang', 'platform');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAccessCatalogueCommand::class,
                GrantPlatformRoleCommand::class,
                CreateAdministratorCommand::class,
                VerifyUserEmailCommand::class,
                PruneExpiredSessionsCommand::class,
            ]);
        }

        ConfiguredRateLimit::register('login-per-account-ip', 'identity.rate_limits.login_per_account_ip', function (Request $request): string {
            $email = $request->string('email')->toString();
            $index = $email === ''
                ? 'no-email'
                : app(BlindIndexHasher::class)->compute(Normalizer::email($email));

            return $index . '|' . $request->ip();
        });

        ConfiguredRateLimit::register('login-per-ip', 'identity.rate_limits.login_per_ip', static fn (Request $request): string => (string) $request->ip());

        /*
         * One budget per account, from however many addresses the guesses come. Before the code is
         * entered nobody is signed in, so the account is the one the pending login is for; keyed on
         * account and address together, as this was, a password holder got five guesses per address.
         */
        $perAuthenticatedUser = static function (Request $request): string {
            $identifier = $request->user()?->getAuthIdentifier();

            return (is_string($identifier) ? $identifier : null)
                ?? PendingMfaLogin::userId($request)
                ?? (string) $request->ip();
        };

        ConfiguredRateLimit::register('mfa-challenge-per-user', 'identity.rate_limits.mfa_challenge_per_user', $perAuthenticatedUser);

        // Step-up submits the same kind of code against the same secret, so it is keyed identically —
        // with its own budget, because exhausting one must not lock a person out of the other.
        ConfiguredRateLimit::register('step-up-per-user', 'identity.rate_limits.step_up_per_user', $perAuthenticatedUser);

        ConfiguredRateLimit::register('passkey-ceremony-per-ip', 'identity.rate_limits.passkey_ceremony_per_ip', static fn (Request $request): string => (string) $request->ip());

        ConfiguredRateLimit::register('register-per-ip', 'identity.rate_limits.register_per_ip', static fn (Request $request): string => (string) $request->ip());

        /*
         * Keyed per account, not per IP. A code belongs to one account, so guessing at it from many
         * addresses must still exhaust one budget — and asking for another code is a request to send
         * mail somewhere, which is throttled by whose inbox it lands in, not by who asked.
         */
        $perUser = ConfiguredRateLimit::perUser();

        ConfiguredRateLimit::register('email-verify-per-user', 'identity.rate_limits.email_verify_per_user', $perUser);
        ConfiguredRateLimit::register('email-resend-per-user', 'identity.rate_limits.email_resend_per_user', $perUser);
        ConfiguredRateLimit::register('operator-calls-per-user', 'identity.rate_limits.operator_calls_per_user', $perUser);
    }
}
