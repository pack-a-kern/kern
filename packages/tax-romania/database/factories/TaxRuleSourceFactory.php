<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Legislation\Database\Factories\SourceSectionFactory;
use Kern\Legislation\Enums\SourceRelation;
use Kern\TaxRomania\TaxRules\TaxRuleSource;

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
