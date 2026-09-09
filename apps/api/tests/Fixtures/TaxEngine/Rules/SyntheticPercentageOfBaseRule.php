<?php

declare(strict_types=1);

namespace Tests\Fixtures\TaxEngine\Rules;

use App\Domain\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use App\Domain\TaxEngine\Contracts\TaxRuleContract;
use App\Domain\TaxEngine\DataTransferObjects\CalculationStep;
use App\Domain\TaxEngine\DataTransferObjects\CalculationTrace;
use App\Domain\TaxEngine\DataTransferObjects\TaxRuleInput;
use App\Domain\TaxEngine\DataTransferObjects\TaxRuleOutcome;
use App\Domain\TaxEngine\Enums\RoundingMode;
use App\Domain\TaxEngine\ValueObjects\Money;

/**
 * SYNTHETIC, TEST-ONLY fixture rule (`docs/AGENTS.md` §2 / the task brief: "your tests supply
 * their own clearly-synthetic parameters"). Not a real Romanian tax rule — no rate, threshold, or
 * ceiling is hardcoded anywhere in this class; the only number involved (`RO.TEST.SYNTHETIC_RATE`)
 * is a `fiscal_parameter_versions` value resolved by `App\Domain\TaxEngine\Services\
 * ParameterResolver` at test-setup time from a factory-created row, never a literal here.
 *
 * Demonstrates the generic "percentage of the summed RON gross base" shape end to end —
 * `App\Domain\TaxEngine\Services\TaxAssessor`'s own tests use this as their
 * `tax_rule_versions.implementation_class` — and is itself proven pure by
 * `tests/Feature/Domain/TaxEngine/RulePurityArchitectureTest.php`.
 */
#[TaxRuleAttribute(code: 'RO.TEST.SYNTHETIC_PERCENTAGE_OF_BASE', version: '0.0.1')]
final class SyntheticPercentageOfBaseRule implements TaxRuleContract
{
    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $rate = $input->parameters->get('RO.TEST.SYNTHETIC_RATE')->asDecimalRate();

        $base = Money::zero('RON');
        foreach ($input->facts as $fact) {
            $base = $base->add($fact->grossAmountRon);
        }

        $tax = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'taxable_base' => $base->toString(),
                'rate' => $rate->toString(),
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_gross_facts',
                    detail: ['fact_count' => (string) count($input->facts)],
                    result: $base->toString(),
                ),
                new CalculationStep(
                    operation: 'multiply_by_rate',
                    detail: ['rate' => $rate->toString()],
                    result: $tax->toString(),
                ),
            ],
            sourceRefs: $input->ruleVersion->sourceSectionIds,
            result: $tax->toString(),
        );

        return new TaxRuleOutcome(
            taxCode: $input->ruleVersion->taxCode,
            categoryCode: 'SYNTHETIC_TEST_CATEGORY',
            taxableBase: $base,
            rate: $rate,
            tax: $tax,
            explanationKey: 'synthetic.test.percentage_of_base',
            trace: $trace,
        );
    }
}
