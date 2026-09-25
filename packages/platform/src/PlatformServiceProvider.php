<?php

declare(strict_types=1);

namespace Kern\Platform;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Crypto\BlindIndex\Contracts\BlindIndexKeyProvider;
use Kern\Platform\KeyManagement\KeyManagementServiceProvider;
use Kern\Platform\ObjectStore\ObjectStoreServiceProvider;
use Kern\Platform\Tenancy\TenantContext;

/**
 * The platform's own wiring: keys, blind indexes, the encrypted object store, the tenant context.
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
        foreach (['crypto', 'objectstore', 'tenancy'] as $file) {
            $this->mergeConfigFrom(__DIR__ . "/../config/{$file}.php", $file);
        }

        $this->app->register(KeyManagementServiceProvider::class);
        $this->app->register(ObjectStoreServiceProvider::class);

        $this->app->singleton(BlindIndexKeyProvider::class, static function (Application $app): BlindIndexKeyProvider {
            $config = $app->make(ConfigRepository::class);
            $keysJson = $config->get('crypto.blind_index.keys');
            $currentVersion = $config->get('crypto.blind_index.current_version');

            return new ConfigBlindIndexKeyProvider(
                is_string($keysJson) ? $keysJson : '',
                is_int($currentVersion) ? $currentVersion : 0,
            );
        });

        $this->app->singleton(BlindIndexHasher::class);

        // Scoped, never a process-lifetime singleton: its state is which tenant is active, and
        // state like that surviving into the next request or queue job is the whole catastrophe
        // tenancy exists to prevent.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/crypto.php' => config_path('crypto.php'),
            __DIR__ . '/../config/objectstore.php' => config_path('objectstore.php'),
            __DIR__ . '/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'kern-platform-config');
    }
}
