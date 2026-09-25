<?php

declare(strict_types=1);

namespace Kern\Audit\Tests;

use Kern\Audit\AuditServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * This package, installed into an empty Laravel application and nothing else.
 *
 * That is the whole point of the suite: the application's own test run proves the audit chain works
 * *here*, where identity, tenancy and eleven tax modules happen to be present. It cannot prove the
 * package is installable, and the first thing this suite found was that it was not — the migration
 * adding the identity foreign keys named `users` and `tax_subjects` unconditionally.
 *
 * Postgres rather than the sqlite Testbench defaults to, because the guarantee under test is
 * `SELECT … FOR UPDATE` on the chain head and a trigger that refuses `UPDATE`/`DELETE`, neither of
 * which sqlite has. The credentials come from the container's own environment.
 */
abstract class TestCase extends Testbench
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class];
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
    }
}
