<?php

declare(strict_types=1);

namespace Kern\Investments;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Console\ImportBvbCommand;
use Kern\Investments\Console\ImportGovernmentBondsCommand;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\Queries\EloquentMarketData;
use Kern\Investments\Listeners\ReadIssuesFromANewOrder;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\Dependents\DocumentDependents;
use Kern\Legislation\Events\LawConsolidationsRead;

/**
 * What is on the market (ADR 0025): the configuration, the sources this package reads, its tables, and
 * the laws its instruments were read from, which may not be deleted from under them.
 *
 * Its sources are added to `kern/legislation`'s registry once every provider has registered, so they
 * land beside the registry's own whichever provider registers first — a merge before that one would be
 * overwritten by it. Through the registry they are fetched like any other: allowlisted, as the
 * installation's identity says.
 */
final class InvestmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/investments.php', 'investments');

        $this->app->booting(static function (Application $app): void {
            $config = $app->make(ConfigRepository::class);

            /** @var array<string, array<string, mixed>> $entries */
            $entries = require __DIR__ . '/../config/sources.php';

            foreach ($entries as $code => $entry) {
                $config->set("sources.entries.{$code}", $entry);
            }
        });

        // Read and checked once, at first use; a value missing fails then, naming its variable.
        $this->app->singleton(
            InvestmentsConfig::class,
            static fn (Application $app): InvestmentsConfig => InvestmentsConfig::read($app->make(ConfigRepository::class), $app->make(OfficialSourceRegistry::class)),
        );

        // What another package may read of the market, as values (ADR 0025 §1).
        $this->app->bind(MarketData::class, EloquentMarketData::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportGovernmentBondsCommand::class, ImportBvbCommand::class]);
        }

        // A bond's terms are read from the order that issued it: that law stays while the bond does.
        $this->app->make(DocumentDependents::class)->declare('instruments', 'ext_financial_instruments', 'terms_document_id');

        // A programme's order, once read as a law, is read for its issues.
        Event::listen(LawConsolidationsRead::class, [ReadIssuesFromANewOrder::class, 'handle']);
    }
}
