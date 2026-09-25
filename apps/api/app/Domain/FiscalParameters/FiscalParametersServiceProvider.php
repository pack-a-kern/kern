<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters;

use App\Domain\FiscalParameters\Listeners\ReadWagesFromANewLaw;
use App\Domain\FiscalParameters\Providers\EloquentFiscalParameterProvider;
use App\Domain\FiscalParameters\Providers\EloquentTaxRuleVersionProvider;
use App\Domain\TaxEngine\Contracts\FiscalParameterProvider;
use App\Domain\TaxEngine\Contracts\TaxRuleVersionProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kern\Legislation\Events\LawConsolidationsRead;

/**
 * The values a Romanian tax is computed from, read out of the law and approved by a person.
 *
 * Binds `App\Domain\TaxEngine`'s two ports ({@see TaxRuleVersionProvider},
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
