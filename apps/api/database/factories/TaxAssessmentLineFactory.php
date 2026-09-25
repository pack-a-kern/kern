<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TaxEngine\Models\TaxAssessmentLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kern\Legislation\Database\Factories\TaxRuleVersionFactory;

/**
 * @extends Factory<TaxAssessmentLine>
 */
final class TaxAssessmentLineFactory extends Factory
{
    protected $model = TaxAssessmentLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => TaxAssessmentFactory::new(),
            'tax_code' => 'SYNTHETIC_TEST_TAX',
            'category_code' => 'SYNTHETIC_TEST_CATEGORY',
            'taxable_base_minor' => 100000,
            'rate_decimal' => '0.10',
            'tax_minor' => 10000,
            'legal_rule_version_id' => TaxRuleVersionFactory::new(),
            'explanation_key' => 'synthetic.test.explanation',
            'calculation_trace_json' => [
                'rule' => 'RO.TEST.SYNTHETIC',
                'rule_version' => '0.0.1',
                'inputs' => [],
                'steps' => [],
                'source_refs' => [],
                'result' => '10000',
            ],
        ];
    }
}
