<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Enums\TrustTier;
use App\Domain\TaxKnowledge\Models\OfficialSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialSource>
 */
final class OfficialSourceFactory extends Factory
{
    protected $model = OfficialSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('SRC???')),
            'trust_tier' => TrustTier::D,
            'base_url' => 'https://example.test',
            'enabled' => true,
        ];
    }
}
