<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;

/**
 * @extends Factory<TaxRuleVersion>
 */
final class TaxRuleVersionFactory extends Factory
{
    protected $model = TaxRuleVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_rule_id' => TaxRuleFactory::new(),
            'semantic_version' => '2026.1.0',
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'implementation_class' => null,
            'config_json' => [],
            'status' => ApprovalStatus::CANDIDATE,
            'source_hash' => null,
            'approved_at' => null,
        ];
    }
}
