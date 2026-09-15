<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Banks\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bank>
 */
final class BankFactory extends Factory
{
    protected $model = Bank::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $letters = strtoupper($this->faker->unique()->lexify('????'));

        return [
            'ecb_code' => 'RORBPJR' . $this->faker->unique()->numerify('#####'),
            'lei' => null,
            'bic' => $letters . 'ROBUXXX',
            'name' => 'Banca ' . ucfirst(strtolower($letters)),
            'legal_name' => 'BANCA ' . $letters . ' S.A.',
            'parent_name' => null,
            'parent_lei' => null,
            'category' => 'Credit Institution',
            'country_code' => 'RO',
            'city' => 'BUCURESTI',
            'postal_code' => null,
            'address' => null,
            'active' => true,
            'first_seen_on' => now('UTC')->toDateString(),
            'last_seen_on' => now('UTC')->toDateString(),
            'bic_checked_on' => now('UTC')->toDateString(),
        ];
    }
}
