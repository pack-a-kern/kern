<?php

declare(strict_types=1);

namespace Kern\Notifications\Tests;

use Kern\Notifications\NotificationsServiceProvider;
use Kern\Notifications\Services\NoticeTypes;
use Kern\Notifications\Tests\Fixtures\Orders;
use Kern\Notifications\Tests\Fixtures\OrderShipped;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * This package in an empty Laravel application and nothing else: no kern package, people with integer
 * ids in a `people` table, and one kind of notice — the README's.
 *
 * Postgres rather than the sqlite Testbench defaults to: the application this was extracted from runs
 * on it, and an integer `notifiable_id` compared with a string is exactly what sqlite would forgive.
 */
abstract class TestCase extends Testbench
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [NotificationsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // In memory, never the stack's Redis: these tests run inside the app container, whose
        // CACHE_STORE, SESSION_DRIVER and QUEUE_CONNECTION name it.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
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
        // Empty unless a test sets a pair: the server's own environment may hold real ones.
        $app['config']->set('notifications.push.vapid.public_key', '');
        $app['config']->set('notifications.push.vapid.private_key', '');
        $app['config']->set('app.url', 'https://shop.example.com');
        // Requests here carry encrypted cookies. A key made per test, never one from the environment:
        // inside the app container APP_KEY is set, on a bare CI runner nothing is, and a suite that
        // passed only where the stack runs was hiding that.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $app->singleton(Orders::class);
        $app->make(NoticeTypes::class)->register(OrderShipped::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }
}
