<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Settings\Infrastructure\SystemDekRepository;
use App\Domain\Settings\Support\SystemSecretCipher;
use App\Support\KeyManagement\Contracts\KeyManagementService;
use App\Support\KeyManagement\SubjectDekService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires this module's two seams that need explicit container construction:
 *
 * 1. `SystemSecretCipher` — built around a **lazy factory** for its own `SubjectDekService`
 *    instance (`SystemSecretCipher`'s own docblock explains why eagerness here is a real bug, not
 *    a style choice), using `SystemDekRepository` rather than the container's shared
 *    `SubjectDekRepository` binding (`App\Domain\Subjects\Infrastructure\
 *    EloquentSubjectDekRepository`, which only knows about `tax_subjects`/`users`). Reuses the
 *    real `SubjectDekService` class verbatim — see that class's own docblock and
 *    `App\Domain\Settings\Support\SystemEncryptionScope` for why a second, independent instance
 *    is correct here rather than a second implementation of the same logic. `SystemSecretCipher`
 *    itself is bound as a singleton (cheap — it only wraps a closure); the `SubjectDekService` the
 *    closure builds on first use caches the unwrapped system DEK for the rest of the process,
 *    correct because there is exactly one system scope for the process's lifetime (unlike the
 *    container's tenant-scoped `SubjectDekService`, which must not outlive one request).
 *
 * Every other class in this module (`SettingsRepository`, `ResolveAiProviderCredentials`,
 * `AiProviderSettingsService`, `TestAiProviderConnection`) has only concrete, container-
 * resolvable dependencies and needs no explicit binding.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SystemSecretCipher::class, static function (Application $app): SystemSecretCipher {
            return new SystemSecretCipher(static function () use ($app): SubjectDekService {
                return new SubjectDekService(
                    $app->make(KeyManagementService::class),
                    new SystemDekRepository(),
                );
            });
        });

    }
}
