<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests;

use Kern\Audit\AuditServiceProvider;
use Kern\Documents\DocumentsServiceProvider;
use Kern\Investments\InvestmentsServiceProvider;
use Kern\Legislation\LegislationServiceProvider;
use Kern\Notifications\NotificationsServiceProvider;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\PlatformServiceProvider;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\TaxRomaniaServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * The whole product installed into an empty Laravel application: six packages and no application.
 *
 * Two things it does *not* need, unlike the suites of the packages it depends on: a `TenantGate` and a
 * `TenantNames`. This package is where a tenant is a taxpayer, so it answers both itself — a
 * non-revoked `subject_memberships` row, and the two encrypted columns a taxpayer's name lives in.
 * That is the shape the whole split was for: the base asks, the product answers, and an application
 * chooses which product to install.
 *
 * It still needs a KMS, because ADR 0002 refuses a fake production driver and there is no OpenBao in
 * a test run. `SubjectDekRepository` is not faked here: the real one reads `tax_subjects`, which this
 * package creates.
 */
abstract class TestCase extends Testbench
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            AuditServiceProvider::class,
            NotificationsServiceProvider::class,
            PlatformServiceProvider::class,
            DocumentsServiceProvider::class,
            LegislationServiceProvider::class,
            InvestmentsServiceProvider::class,
            TaxRomaniaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // In memory, never the stack's Redis: these tests run inside the app container, whose
        // CACHE_STORE, SESSION_DRIVER and QUEUE_CONNECTION name it, and this skeleton's Redis
        // configuration knows nothing of its TLS.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        // Nowhere, for the same reason: the container's BROADCAST_CONNECTION names Reverb, and this
        // package does not install the Pusher client that broadcaster needs. A taxpayer's first
        // membership announces itself (`TaxpayersChanged`), so the suite would otherwise fail on
        // setting one up.
        $app['config']->set('broadcasting.default', 'null');
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

        // A taxpayer, which is what this package installs the tenant as.
        $app['config']->set('tenancy.column', 'tax_subject_id');
        $app['config']->set('tenancy.table', 'tax_subjects');

        $app['config']->set('filesystems.disks.objects', ['driver' => 'local', 'root' => $app->storagePath('kern-objects')]);
        $app['config']->set('objectstore.disk', 'objects');
        $app['config']->set('documents.quarantine_disk', 'objects');
        $app['config']->set('sources.storage.disk', 'objects');
        $app['config']->set('ai.embeddings.enabled', false);

        $app->instance(KeyManagementService::class, new InMemoryKeyManagementService());
    }
}
