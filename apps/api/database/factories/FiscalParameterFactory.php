<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

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
