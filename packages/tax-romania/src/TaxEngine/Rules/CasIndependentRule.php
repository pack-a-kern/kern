<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Rules;

use Kern\Platform\Money\CurrencyCode;
use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\CalculationStep;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\CalculationTrace;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleInput;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleOutcome;
use Kern\TaxRomania\TaxEngine\Enums\RoundingMode;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;
use Kern\TaxRomania\TaxEngine\ValueObjects\WageThresholds;

/**
 * Pension contribution on income from independent activity.
 *
 * ## A step, like the health contribution on other income — and nothing like the one beside it
 *
 * `RO.CAS.BASE_MIN_WAGES` is `{"wages": [12, 24]}`: thresholds the income is compared against, and the
 * contribution is a percentage of the threshold reached. So this shares its shape with
 * {@see CassOtherIncomeRule} and not with {@see CassIndependentRule}, which applies to the same income with a
 * floor-and-cap instead. Two contributions on one kind of income, computed two different ways, which is why
 * each is its own rule rather than a branch somewhere.
 *
 * Below the first threshold nothing is owed. The rate is 25% rather than 10%, from `RO.CAS.RATE`.
 *
 * ## Only independent activity
 *
 * Salary has its own regime and its employer withholds it; rent, dividends and interest owe no pension
 * contribution at all. So this reads one category, and the assessor handing every rule the whole year is why
 * it has to say so.
 */
#[TaxRuleAttribute(
    code: 'RO.PF.CAS.INDEPENDENT',
    version: '1.0.0',
    categories: [CasIndependentRule::CATEGORY],
    parameters: [
        CasIndependentRule::MINIMUM_WAGE,
        CasIndependentRule::RATE,
        CasIndependentRule::THRESHOLDS,
    ],
)]
final class CasIndependentRule implements TaxRuleContract
{
    public const string CATEGORY = 'FREELANCE';

    public const string MINIMUM_WAGE = 'RO.MIN_WAGE';

    public const string RATE = 'RO.CAS.RATE';

    public const string THRESHOLDS = 'RO.CAS.BASE_MIN_WAGES';

    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $minimumWage = $input->parameters->get(self::MINIMUM_WAGE)->asMoney(CurrencyCode::BASE);
        $rate = $input->parameters->get(self::RATE)->asDecimalRate();
        $wageMultiples = WageThresholds::ascendingFrom($input->parameters->get(self::THRESHOLDS)->asJson());

        $income = Money::zero(CurrencyCode::BASE);
        $counted = 0;

        foreach ($input->facts as $fact) {
            if ($fact->categoryCode !== self::CATEGORY) {
                continue;
            }

            $income = $income->add($fact->grossAmountRon);
            $counted++;
        }

        [$base, $reached, $thresholds] = WageThresholds::reach($income, $minimumWage, $wageMultiples);

        $contribution = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'independent_income' => $income->toString(),
                'minimum_wage' => $minimumWage->toString(),
                'rate' => $rate->toString(),
                ...$thresholds,
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_independent_income',
                    detail: ['fact_count' => (string) $counted],
                    result: $income->toString(),
                ),
                new CalculationStep(
                    operation: 'reach_threshold',
                    detail: [
                        'threshold_in_wages' => $reached === null ? 'none' : (string) $reached,
                        ...$thresholds,
                    ],
                    result: $base->toString(),
                ),
                new CalculationStep(
                    operation: 'multiply_threshold_by_rate',
                    detail: ['rate' => $rate->toString()],
                    result: $contribution->toString(),
                ),
            ],
            sourceRefs: $input->ruleVersion->sourceSectionIds,
            result: $contribution->toString(),
        );

        return new TaxRuleOutcome(
            taxCode: $input->ruleVersion->taxCode,
            categoryCode: self::CATEGORY,
            taxableBase: $base,
            rate: $rate,
            tax: $contribution,
            explanationKey: $reached === null
                ? 'tax.ro.pf.cas.independent.below_threshold'
                : 'tax.ro.pf.cas.independent.threshold_reached',
            trace: $trace,
        );
    }
}
