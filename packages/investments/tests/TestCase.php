<?php

declare(strict_types=1);

namespace Kern\Investments\Tests;

use Kern\Audit\AuditServiceProvider;
use Kern\Investments\InvestmentsServiceProvider;
use Kern\Legislation\LegislationServiceProvider;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\PlatformServiceProvider;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * This package with what it requires — the platform, the audit chain, legislation — and no tax code:
 * the market is the same whoever holds it.
 *
 * Postgres, as the other package suites: the name search's index is Postgres-only. The configuration
 * is set here, never read from the stack's environment.
 */
abstract class TestCase extends Testbench
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class, PlatformServiceProvider::class, LegislationServiceProvider::class, InvestmentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
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
        $app['config']->set('sources.identify_as', 'browser');
        $app['config']->set('sources.browser.user_agent', 'Mozilla/5.0 (Test) Chrome/128.0.0.0');
        $app['config']->set('sources.crawler.name', 'Kern-Test-Crawler/1.0');
        $app['config']->set('ai.embeddings.enabled', false);
        $app['config']->set('investments.bvb', [
            'shares_url' => 'https://www.bvb.ro/shares',
            'bonds_url' => 'https://www.bvb.ro/bonds',
            'dividends_url' => 'https://www.bvb.ro/dividends',
            'instrument_url' => 'https://www.bvb.ro/instrument?s={symbol}',
        ]);

        $app->instance(KeyManagementService::class, new InMemoryKeyManagementService());
        $app->instance(SubjectDekRepository::class, new InMemoryDekRepository());
        $app->forgetInstance(EmbeddingProvider::class);
    }
}
