<?php

declare(strict_types=1);

namespace Kern\Documents\ObjectStore;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\KeyManagement\SubjectDekService;

/**
 * Binds {@see EncryptedObjectStore} against the disk/chunk size in `config/objectstore.php`.
 *
 * Deliberately not a singleton: `EncryptedObjectStore` depends on `SubjectDekService`, which is
 * itself bound scoped (per-request) by `Kern\Platform\KeyManagement\KeyManagementServiceProvider`
 * (docs/handoff/005-crypto-core.md). Resolving `EncryptedObjectStore` fresh on every request keeps
 * it wired to whichever `SubjectDekService` instance is current, rather than pinning a stale one
 * captured at container-boot time by a singleton.
 */
final class ObjectStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EncryptedObjectStore::class, static function (Application $app): EncryptedObjectStore {
            $config = $app->make(ConfigRepository::class);
            $disk = $app->make(FilesystemFactory::class)->disk($config->string('objectstore.disk'));

            return new EncryptedObjectStore(
                $disk,
                $app->make(SubjectDekService::class),
                $config->integer('objectstore.chunk_size'),
            );
        });
    }
}
