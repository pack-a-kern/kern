<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Models\TaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRule>
 */
final class TaxRuleFactory extends Factory
{
    protected $model = TaxRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'RO.TEST.' . strtoupper($this->faker->unique()->lexify('RULE???')),
            'jurisdiction' => 'RO',
            'tax_code' => 'TEST_TAX',
            'subject_type' => 'PF',
            'income_category' => null,
        ];
    }
}
