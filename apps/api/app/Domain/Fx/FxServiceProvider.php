<?php

declare(strict_types=1);

namespace App\Domain\Fx;

use App\Domain\Fx\Services\FxPeriodAverageCalculator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the one class in this module needing scalar values out of `config/sources.php`'s `fx.*`
 * block. Everything else (`FxSourceRepository`, `FxRateImporter`, `FxRateQuery`,
 * `App\Domain\Fx\Actions\*`) has only class-typed dependencies the container resolves on its own.
 */
final class FxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FxPeriodAverageCalculator::class, static function (Application $app): FxPeriodAverageCalculator {
            $config = $app->make(ConfigRepository::class);
            $tolerance = $config->get('sources.fx.verification_tolerance');
            $decimalScale = $config->get('sources.fx.decimal_scale');

            return new FxPeriodAverageCalculator(
                is_int($decimalScale) ? $decimalScale : 6,
                is_string($tolerance) ? $tolerance : '0',
            );
        });
    }
}
