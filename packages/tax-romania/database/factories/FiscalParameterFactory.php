<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;

/**
 * @extends Factory<FiscalParameter>
 */
final class FiscalParameterFactory extends Factory
{
    protected $model = FiscalParameter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'RO.TEST.' . strtoupper($this->faker->unique()->lexify('PARAM???')),
            'value_type' => FiscalParameterValueType::MONEY,
            'description' => 'Synthetic fixture parameter, not a real fiscal value.',
        ];
    }
}
