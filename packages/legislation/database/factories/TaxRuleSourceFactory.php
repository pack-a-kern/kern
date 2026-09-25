<?php

declare(strict_types=1);

namespace Kern\Legislation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Legislation\Enums\SourceRelation;
use Kern\Legislation\Models\TaxRuleSource;

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
