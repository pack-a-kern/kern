<?php

declare(strict_types=1);

namespace Kern\Platform\Settings;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\Console\Commands\Settings\CheckSystemHealthCommand;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Routing\PackageRoutes;
use Kern\Platform\Settings\Infrastructure\SystemDekRepository;
use Kern\Platform\Settings\Services\StoredSettingsOverlay;
use Kern\Platform\Settings\Support\SystemSecretCipher;

/**
 * Wires this module's one seam that needs explicit container construction.
 *
 * `SystemSecretCipher` is built around a **lazy factory** for its own `SubjectDekService` instance
 * (`SystemSecretCipher`'s own docblock explains why eagerness here is a real bug, not a style
 * choice), using `SystemDekRepository` rather than the container's shared `SubjectDekRepository`
 * binding — the application's, which only knows about its own tenant table. It reuses the real
 * `SubjectDekService` class verbatim; see that class's docblock and
 * {@see \Kern\Platform\Settings\Support\SystemEncryptionScope} for why a second, independent
 * instance is correct here rather than a second implementation of the same logic.
 *
 * `SystemSecretCipher` itself is a singleton — cheap, it only wraps a closure — and the
 * `SubjectDekService` the closure builds on first use caches the unwrapped system DEK for the rest
 * of the process. Correct, because there is exactly one system scope for a process's lifetime,
 * unlike the container's tenant-scoped `SubjectDekService`, which must not outlive one request.
 *
 * Every other class here (`SettingsRepository`, `ResolveAiProviderCredentials`,
 * `AiProviderSettingsService`, `TestAiProviderConnection`) has only concrete, container-resolvable
 * dependencies and needs no binding.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/ai/providers.php', 'ai.providers');

        $this->app->singleton(SystemSecretCipher::class, static function (Application $app): SystemSecretCipher {
            return new SystemSecretCipher(static function () use ($app): SubjectDekService {
                return new SubjectDekService(
                    $app->make(KeyManagementService::class),
                    new SystemDekRepository(),
                );
            });
        });
    }

    public function boot(): void
    {
        PackageRoutes::load(__DIR__ . '/../../routes/settings.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckSystemHealthCommand::class]);
        }

        /*
         * Before anything reads a configuration value, and after every package's own config has been
         * merged — which is what `boot` guarantees and `register` does not.
         *
         * Without this the screen wrote rows nobody read: see `StoredSettingsOverlay` for how thirty-one
         * of thirty-eight settings came to be decorative.
         */
        $this->app->make(StoredSettingsOverlay::class)->apply();
    }
}
