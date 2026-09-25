<?php

declare(strict_types=1);

namespace App\Domain\Subjects;

use App\Domain\Subjects\Infrastructure\EloquentSubjectDekRepository;
use App\Domain\Subjects\Tenancy\MembershipTenantGate;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\Crypto\BlindIndex\Contracts\BlindIndexKeyProvider;
use App\Support\KeyManagement\Contracts\SubjectDekRepository;
use App\Support\Tenancy\Contracts\TenantGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the persistence contract `docs/handoff/005-crypto-core.md` left for this module — once
 * this binding exists, `App\Support\KeyManagement\SubjectDekService` (already registered as a
 * scoped singleton by `App\Support\KeyManagement\KeyManagementServiceProvider`) becomes fully
 * resolvable via the container, exactly as that handoff's "Interfaces exposed to other modules"
 * section describes.
 *
 * `TenantContext` is registered `scoped()`, not a process-lifetime singleton, for the same reason
 * `SubjectDekService` is: its mutable "currently active subject" state must not survive past one
 * HTTP request or queue job. php-fpm already bounds this in normal request handling (a fresh
 * `Application` container per request); long-running workers must not reuse an instance across
 * jobs — see that class's own docblock.
 *
 * **Also binds `App\Support\Crypto\BlindIndex\Contracts\BlindIndexKeyProvider` /
 * `BlindIndexHasher`.** Neither had a container binding anywhere in this codebase before this
 * task — `config/crypto.php` reads `CRYPTO_BLIND_INDEX_KEYS`, but nothing constructed
 * `ConfigBlindIndexKeyProvider` from it (every existing crypto-core test builds one directly,
 * never via the container — confirmed by reading `docs/handoff/005-crypto-core.md` and the
 * existing `BlindIndexHasherTest`/`NormalizerTest` before relying on this claim). This module
 * needs `app(BlindIndexHasher::class)` to resolve for email/CNP lookups
 * (`App\Domain\Identity\Models\User::scopeWhereEmail`, `App\Domain\Subjects\Actions\
 * RegisterPfSubject`), so the binding is added here rather than left missing a second time.
 * Recorded in `docs/handoff/011-identity-tenancy.md` as a gap found outside this task's owned
 * files (`app/Support/Crypto/**` belongs to the crypto-core task) — a future `Support`-level
 * provider is free to supersede this registration; nothing here assumes it stays here forever.
 */
final class SubjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubjectDekRepository::class, EloquentSubjectDekRepository::class);
        // What "may act for this taxpayer" means is this application's answer, given to the
        // platform's tenant context rather than known by it.
        $this->app->bind(TenantGate::class, MembershipTenantGate::class);
        $this->app->scoped(TenantContext::class);

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
    }
}
