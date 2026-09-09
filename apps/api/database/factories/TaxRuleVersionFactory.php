<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\Models\TaxRuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'status' => RuleApprovalStatus::CANDIDATE,
            'source_hash' => null,
            'approved_at' => null,
        ];
    }
}
