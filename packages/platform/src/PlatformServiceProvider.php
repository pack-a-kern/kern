<?php

declare(strict_types=1);

namespace Kern\Platform;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\Console\Commands\Database\GrantApplicationRoleCommand;
use Kern\Platform\Console\Commands\Database\UpdateExtensionsCommand;
use Kern\Platform\Console\Commands\Environment\BuildEnvironmentFileCommand;
use Kern\Platform\Console\Commands\KeyManagement\ReindexBlindIndexesCommand;
use Kern\Platform\Console\Commands\KeyManagement\RewrapDeksCommand;
use Kern\Platform\Console\Commands\KeyManagement\RotateKekCommand;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Identity\IdentityServiceProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\KeyManagementServiceProvider;
use Kern\Platform\Logging\Redactions;
use Kern\Platform\Notifications\NotificationsServiceProvider;
use Kern\Platform\Settings\SettingsServiceProvider;
use Kern\Platform\Tenancy\TenantContext;

/**
 * The platform's own wiring: keys, blind indexes, the tenant context.
 *
 * One provider, so an application requires the package and has working encryption rather than
 * remembering to register several things in the right order. The configuration is merged
 * *underneath* the application's own, which means an installation overrides exactly what it cares
 * about — the column its tenant is called, usually, and nothing else.
 *
 * `encryption_key_records` travels with the package for the same reason the audit tables travel
 * with theirs: a per-tenant data key has nowhere to live otherwise, and a package whose table the
 * application has to remember to create is a package that works until someone forgets.
 *
 * What is deliberately *not* here: the {@see \Kern\Platform\Tenancy\Contracts\TenantGate} that says
 * who may act for whom, and the {@see \Kern\Platform\KeyManagement\Contracts\SubjectDekRepository}
 * that says where a tenant's wrapped key is stored. Both are answers only the application has, so
 * the application binds them.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (['crypto', 'kern', 'tenancy'] as $file) {
            $this->mergeConfigFrom(__DIR__ . "/../config/{$file}.php", $file);
        }

        $this->app->register(KeyManagementServiceProvider::class);
        $this->app->register(SettingsServiceProvider::class);
        $this->app->register(IdentityServiceProvider::class);
        $this->app->register(NotificationsServiceProvider::class);

        $this->app->singleton(BlindIndexHasher::class);

        // What must never reach a log file. The platform's own shapes are in the processor; this is
        // where a package adds the identifiers only it can recognise.
        $this->app->singleton(Redactions::class);

        // Scoped, never a process-lifetime singleton: its state is which tenant is active, and
        // state like that surviving into the next request or queue job is the whole catastrophe
        // tenancy exists to prevent.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        /*
         * `users` rows are referenced polymorphically as `user`, never as a class name.
         *
         * Spatie's `model_has_roles.model_type` and Laravel's `notifications.notifiable_type` store
         * whatever `getMorphClass()` returns, and by default that is the fully qualified class name.
         * So moving `User` from `App\Domain\Identity\Models` to this package silently detached every
         * role assignment and every notification in an existing installation: the rows still named a
         * class that no longer exists, so the user held no roles and saw no notices, and the only
         * symptom was a 403 on every administrative screen.
         *
         * An alias fixes it for good — the stored value no longer depends on where the class lives.
         */
        Relation::morphMap(['user' => User::class]);

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildEnvironmentFileCommand::class,
                ReindexBlindIndexesCommand::class,
                RotateKekCommand::class,
                RewrapDeksCommand::class,
                GrantApplicationRoleCommand::class,
                UpdateExtensionsCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/../config/crypto.php' => config_path('crypto.php'),
            __DIR__ . '/../config/kern.php' => config_path('kern.php'),
            __DIR__ . '/../config/ai/providers.php' => config_path('ai/providers.php'),
            __DIR__ . '/../config/access.php' => config_path('access.php'),
            __DIR__ . '/../config/identity.php' => config_path('identity.php'),
            __DIR__ . '/../config/webauthn.php' => config_path('webauthn.php'),
            __DIR__ . '/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'kern-platform-config');
    }
}
