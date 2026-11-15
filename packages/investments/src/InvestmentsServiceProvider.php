<?php

declare(strict_types=1);

namespace Kern\Investments;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Console\ImportBvbCommand;
use Kern\Investments\Console\ImportGovernmentBondsCommand;
use Kern\Investments\Contracts\MarketData;
use Kern\Investments\Listeners\ForgetTheIssuesOfADeletedOrder;
use Kern\Investments\Listeners\ReadIssuesFromANewOrder;
use Kern\Investments\Models\TreasuryOrder;
use Kern\Investments\Queries\EloquentMarketData;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\Events\LawConsolidationsRead;
use Kern\Legislation\Events\LawDeleting;
use Kern\Legislation\Queries\LawFlags;
use Kern\Platform\Routing\ConfiguredRateLimit;
use Kern\Platform\Routing\PackageRoutes;

/**
 * What is on the market (ADR 0025): the configuration, the sources this package reads, its tables, and
 * the laws its instruments were read from, which may not be deleted from under them.
 *
 * Its sources and its law watches are added to `kern/legislation`'s once every provider has registered, so
 * they land beside legislation's own whichever provider registers first — a merge before that one would be
 * overwritten by it. Through the registry they are fetched like any other: allowlisted, as the
 * installation's identity says.
 */
final class InvestmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/investments.php', 'investments');
        // What the browser worker does on BVB's site, as instructions rather than code.
        $this->mergeConfigFrom(__DIR__ . '/../config/playbooks/bvb.php', 'playbooks.bvb');

        $this->app->booting(static function (Application $app): void {
            $config = $app->make(ConfigRepository::class);

            /** @var array<string, array<string, mixed>> $entries */
            $entries = require __DIR__ . '/../config/sources.php';

            foreach ($entries as $code => $entry) {
                $config->set("sources.entries.{$code}", $entry);
            }

            // Each programme's orders, watched for on the portal by kern/legislation — merged in whole: a
            // watch's key has a dot in it, which `set()` would read as one level deeper.
            $config->set('legislation.watches.declared', [...$config->array('legislation.watches.declared', []), ...require __DIR__ . '/../config/watches.php']);

            // The legislation the bonds feature requires (ADR 0027) — merged in whole for the same
            // reason: a requirement's key has a dot in it.
            $config->set('legislation.requirements.declared', [...$config->array('legislation.requirements.declared', []), ...require __DIR__ . '/../config/legislation_requirements.php']);
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

        // A bond's terms are read from the order that issued it: deleting that law, nobody holding one,
        // takes them with it.
        Event::listen(LawDeleting::class, [ForgetTheIssuesOfADeletedOrder::class, 'handle']);

        // An order that listed bonds another order already wrote says so beside it, on the laws screen.
        $this->app->make(LawFlags::class)->declare('bonds_skipped', static fn (array $lawIds): array => TreasuryOrder::query()
            ->whereIn('source_document_id', $lawIds)
            ->whereNotNull('skipped_issues')
            ->get(['source_document_id', 'skipped_issues'])
            ->mapWithKeys(static fn (TreasuryOrder $order): array => [$order->source_document_id => count($order->skipped_issues ?? [])])
            ->all());

        // A programme's order, once read as a law, is read for its issues.
        Event::listen(LawConsolidationsRead::class, [ReadIssuesFromANewOrder::class, 'handle']);

        // Each person's budgets, from the validated configuration: read at the request, so a value missing
        // fails there by name rather than as a limit of nought.
        foreach (['investments-reads-per-user' => 'reads', 'investments-refreshes-per-user' => 'refreshes'] as $name => $budget) {
            RateLimiter::for($name, fn (Request $request): Limit => new Limit(
                ConfiguredRateLimit::perUser()($request),
                ...$this->app->make(InvestmentsConfig::class)->api->{$budget},
            ));
        }

        PackageRoutes::load(__DIR__ . '/../routes/api.php');
    }
}
