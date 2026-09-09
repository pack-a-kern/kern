<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Enums\SourceRelation;
use App\Domain\TaxKnowledge\Models\TaxRuleSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRuleSource>
 */
final class TaxRuleSourceFactory extends Factory
{
    protected $model = TaxRuleSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule_version_id' => TaxRuleVersionFactory::new(),
            'source_section_id' => SourceSectionFactory::new(),
            'relation' => SourceRelation::PRIMARY,
        ];
    }
}
