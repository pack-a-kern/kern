<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kern\Legislation\Events\LawConsolidationsRead;
use Kern\TaxRomania\FiscalParameters\Listeners\ReadWagesFromANewLaw;
use Kern\TaxRomania\FiscalParameters\Providers\EloquentFiscalParameterProvider;
use Kern\TaxRomania\FiscalParameters\Providers\EloquentTaxRuleVersionProvider;
use Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleVersionProvider;

/**
 * The values a Romanian tax is computed from, read out of the law and approved by a person.
 *
 * Binds `Kern\TaxRomania\TaxEngine`'s two ports ({@see TaxRuleVersionProvider},
 * {@see FiscalParameterProvider}) to the Eloquent adapters here — the one place the dependency
 * inversion in `docs/adr/0010-tax-engine-ports-and-adapters.md` is wired up. `bind()`, not
 * `singleton()`: both adapters are stateless and cheap to construct.
 *
 * Also listens for a law having been read. `kern/legislation` says which document it finished and
 * nothing more; whether that particular government decision sets the minimum wage is a fiscal
 * question, and `fiscal_parameters.minimum_wage` is where it is asked. The sync job used to dispatch
 * the reader itself, which meant a package that reads laws could not be installed without the module
 * that computes Romanian taxes.
 */
final class FiscalParametersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxRuleVersionProvider::class, EloquentTaxRuleVersionProvider::class);
        $this->app->bind(FiscalParameterProvider::class, EloquentFiscalParameterProvider::class);
    }

    public function boot(): void
    {
        Event::listen(LawConsolidationsRead::class, [ReadWagesFromANewLaw::class, 'handle']);
    }
}
