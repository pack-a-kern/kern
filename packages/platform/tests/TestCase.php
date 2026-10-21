<?php

declare(strict_types=1);

namespace Kern\Platform\Tests;

use Kern\Audit\AuditServiceProvider;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\PlatformServiceProvider;
use Kern\Platform\Tenancy\Contracts\TenantGate;
use Kern\Platform\Tenancy\Contracts\TenantNames;
use Kern\Platform\Testing\ArrayTenantNames;
use Kern\Platform\Testing\EveryoneIsAMember;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * The platform installed into an empty Laravel application, with nothing product-shaped present.
 *
 * The four bindings below are the whole contract this package asks of an application, and stating
 * them in four lines is the clearest available proof of how small it is: a KMS, where a tenant's
 * wrapped key is kept, who may act for a tenant, and what a tenant is called. Everything else — the
 * blind-index hasher, the object store, the tenant context — the package wires itself.
 *
 * They come from `Kern\Platform\Testing`, which the package ships so that every package built on it
 * can test against the same doubles rather than writing four copies.
 */
abstract class TestCase extends Testbench
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class, PlatformServiceProvider::class];
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

        $app->instance(KeyManagementService::class, new InMemoryKeyManagementService());
        $app->instance(SubjectDekRepository::class, new InMemoryDekRepository());
        $app->bind(TenantGate::class, EveryoneIsAMember::class);
        $app->bind(TenantNames::class, ArrayTenantNames::class);
    }
}
