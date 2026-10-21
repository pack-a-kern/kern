<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests;

use Kern\Audit\AuditServiceProvider;
use Kern\Legislation\LegislationServiceProvider;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\PlatformServiceProvider;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Legislation installed with the platform and the audit chain, and no tax code anywhere.
 *
 * This is the proof ADR 0016 named as the one that matters: reading and searching a law in an
 * application that has never heard of a taxpayer. No `TenantGate` and no `TenantNames` are bound,
 * because the law belongs to nobody — every table here is unscoped, which is why searching it needs
 * no tenant at all.
 *
 * Embeddings are off: `ai.embeddings.enabled` defaults to false and stays there, so search is
 * lexical plus citation similarity. That is the shipped default for the same reason it is right here —
 * an installation without the embedding service must degrade rather than fail mid-backfill.
 */
abstract class TestCase extends Testbench
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class, PlatformServiceProvider::class, LegislationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // In memory, never the stack's Redis: these tests run inside the app container, whose
        // CACHE_STORE, SESSION_DRIVER and QUEUE_CONNECTION name it, and this skeleton's Redis
        // configuration knows nothing of its TLS.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'postgres'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('KERN_PACKAGE_TEST_DATABASE', 'kern_pkg_test'),
            'username' => env('DB_USERNAME', 'kern'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        $app['config']->set('filesystems.disks.sources', [
            'driver' => 'local',
            'root' => $app->storagePath('kern-sources'),
        ]);
        $app['config']->set('sources.storage.disk', 'sources');
        $app['config']->set('sources.storage.prefix', 'sources');
        $app['config']->set('ai.embeddings.enabled', false);

        // Reading a law is queued work — one consolidation per job — and this suite wants the reading
        // itself, not a queue. `sync` runs each job where it is dispatched.
        $app['config']->set('queue.default', 'sync');

        // The platform is required for its crypto and its object store; nothing here is tenant-scoped,
        // but the container still has to be able to resolve what it registers.
        $app->instance(KeyManagementService::class, new InMemoryKeyManagementService());
        $app->instance(SubjectDekRepository::class, new InMemoryDekRepository());
        $app->forgetInstance(EmbeddingProvider::class);
    }
}
