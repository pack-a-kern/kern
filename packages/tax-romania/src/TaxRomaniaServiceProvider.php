<?php

declare(strict_types=1);

namespace Kern\TaxRomania;

use Illuminate\Support\ServiceProvider;
use Kern\Platform\Routing\PackageRoutes;
use Kern\TaxRomania\Console\Commands\Banks\BackfillBanksCommand;
use Kern\TaxRomania\Console\Commands\Banks\ImportBanksCommand;
use Kern\TaxRomania\Console\Commands\Fx\BackfillBnrYearCommand;
use Kern\TaxRomania\Console\Commands\Fx\ImportBnrDailyRatesCommand;
use Kern\TaxRomania\Console\Commands\Wallet\IndexCounterpartiesCommand;
use Kern\TaxRomania\Console\Commands\Wallet\PostDepositInterestCommand;
use Kern\TaxRomania\Declarations\DeclarationsServiceProvider;
use Kern\TaxRomania\FiscalParameters\FiscalParametersServiceProvider;
use Kern\TaxRomania\Fx\FxServiceProvider;
use Kern\TaxRomania\Spv\SpvServiceProvider;
use Kern\TaxRomania\StatementImport\StatementImportServiceProvider;
use Kern\TaxRomania\Subjects\SubjectsServiceProvider;

/**
 * Romanian tax for an individual: the taxpayer, what the law says a tax costs, and everything a
 * return is built from.
 *
 * One package rather than nine, and deliberately. `TaxEngine`, `Declarations`, `Fx`, `Banks`,
 * `Rental`, `Spv`, `Wallet` and `StatementImport` have essentially one consumer between them —
 * each other — and `StatementImport` depends on most of the rest precisely because it is where a
 * wallet and a document meet. Splitting further would buy version numbers and cross-repository test
 * matrices, and would buy no reuse: nobody wants Romanian rental accruals without Romanian income
 * tax.
 *
 * The modules keep their own providers; this one registers them, so requiring the package is enough.
 *
 * Two things it does not decide. The tenant column is `config('tenancy.column')` like everywhere
 * else — this package is the reason it is `tax_subject_id`, but the word still lives in the
 * installation's config, so `kern/documents` can be told the same thing. And its screens are not
 * here: the Inertia pages, the shell and its navigation belong to the application, which is what
 * makes replacing React with Blade a change to one directory.
 */
final class TaxRomaniaServiceProvider extends ServiceProvider
{
    /**
     * Config this package ships, merged under the application's own.
     *
     * `reference/*` are the lists that are facts about Romania rather than decisions about this
     * installation — counties, months, the legal-form words in a company name, the tax categories a
     * transaction may be labelled with. They are configuration so that a screen and a validator read
     * the same list, not so that anyone edits them.
     *
     * @var list<string>
     */
    private const array CONFIG = [
        'banks',
        'fiscal_parameters',
        'operations',
        'spv',
        'statement_import',
        'subjects',
        'wallet',
        'ai/statements',
        'reference/counties',
        'reference/countries',
        'reference/months',
        'reference/party_names',
        'reference/tax_categories',
    ];

    public function register(): void
    {
        foreach (self::CONFIG as $file) {
            $this->mergeConfigFrom(__DIR__ . "/../config/{$file}.php", str_replace('/', '.', $file));
        }

        $this->app->register(SubjectsServiceProvider::class);
        $this->app->register(FiscalParametersServiceProvider::class);
        $this->app->register(FxServiceProvider::class);
        $this->app->register(DeclarationsServiceProvider::class);
        $this->app->register(SpvServiceProvider::class);
        $this->app->register(StatementImportServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        PackageRoutes::load(__DIR__ . '/../routes/api.php');

        $this->publishes(
            array_combine(
                array_map(static fn (string $f): string => __DIR__ . "/../config/{$f}.php", self::CONFIG),
                array_map(static fn (string $f): string => config_path("{$f}.php"), self::CONFIG),
            ),
            'kern-tax-romania-config',
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportBanksCommand::class,
                BackfillBanksCommand::class,
                ImportBnrDailyRatesCommand::class,
                BackfillBnrYearCommand::class,
                IndexCounterpartiesCommand::class,
                PostDepositInterestCommand::class,
            ]);
        }
    }
}
