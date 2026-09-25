<?php

declare(strict_types=1);

namespace Kern\Documents\Tests;

use Kern\Audit\AuditServiceProvider;
use Kern\Documents\DocumentsServiceProvider;
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
 * Documents installed with the platform and the audit chain, and nothing else.
 *
 * No taxpayer, no tax engine, no `tax_subjects` table — and a tenant called a workspace, to keep the
 * package honest about whose word `tax_subject_id` is.
 *
 * The object store writes to a local disk here rather than to MinIO. What is under test is that a
 * document's bytes are encrypted before they are handed to a disk, which is true of any Flysystem
 * adapter; that S3 in particular works is the application's integration test, and it has one.
 */
abstract class TestCase extends Testbench
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class, PlatformServiceProvider::class, DocumentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
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

        // A workspace, not a taxpayer.
        $app['config']->set('tenancy.column', 'workspace_id');
        $app['config']->set('tenancy.table', null);

        $app['config']->set('crypto.blind_index.keys', json_encode(
            ['1' => base64_encode(str_repeat("\x22", 32))],
            JSON_THROW_ON_ERROR,
        ));
        $app['config']->set('crypto.blind_index.current_version', 1);

        $app['config']->set('filesystems.disks.objects', ['driver' => 'local', 'root' => sys_get_temp_dir() . '/kern-objects']);
        $app['config']->set('objectstore.disk', 'objects');
        $app['config']->set('documents.quarantine_disk', 'objects');

        $app->instance(KeyManagementService::class, new InMemoryKeyManagementService());
        $app->instance(SubjectDekRepository::class, new InMemoryDekRepository());
        $app->bind(TenantGate::class, EveryoneIsAMember::class);
        $app->bind(TenantNames::class, ArrayTenantNames::class);
    }
}
