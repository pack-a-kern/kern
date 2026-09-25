<?php

declare(strict_types=1);

namespace Kern\Legislation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Legislation\Enums\TrustTier;
use Kern\Legislation\Models\OfficialSource;

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
