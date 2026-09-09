<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Fx\Models\FxSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FxSource>
 */
final class FxSourceFactory extends Factory
{
    protected $model = FxSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'official_source_id' => OfficialSourceFactory::new(),
            'code' => strtoupper($this->faker->unique()->lexify('FX???')),
            'priority' => 1,
        ];
    }
}
