<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Rules;

use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\CalculationStep;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\CalculationTrace;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleInput;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleOutcome;
use Kern\TaxRomania\TaxEngine\Enums\RoundingMode;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * Health contribution on income other than salary.
 *
 * ## Why this is a step and not a percentage
 *
 * The contribution is not a share of what was earned. Income is totalled, compared against thresholds
 * expressed as multiples of the minimum wage, and the contribution is a percentage of **the threshold
 * reached** — so two people earning seven and eleven minimum wages pay exactly the same, and somebody a
 * leu short of the first threshold pays nothing at all.
 *
 * That is the single thing a taxpayer most wants explained, and it is why the trace records the total,
 * every threshold, and which one was reached, rather than only the answer.
 *
 * ## Why it takes every category at once
 *
 * The thresholds apply to the *sum* of qualifying income, not to each kind separately: dividends and
 * interest and rent and crypto gains are added together and compared once. A rule per category could
 * not see the total, so there is one rule and the producer hands it every qualifying fact.
 *
 * Interest and dividends reach it having already had income tax withheld at source — which this does not
 * care about. Withholding settles the *tax*; the contribution is a separate obligation the income still
 * counts toward, and conflating the two is how somebody concludes they owe nothing because the bank
 * already took something.
 *
 * ## Every number is approved and dated
 *
 * `RO.CASS.OTHER_INCOME_BANDS_WAGES` holds the multiples, `RO.MIN_WAGE` what they multiply, and
 * `RO.CASS.RATE` the percentage — each resolved for the date being assessed. A year that changes its
 * thresholds changes them in the database.
 */
#[TaxRuleAttribute(code: 'RO.PF.CASS.OTHER_INCOME', version: '2026.1')]
final class CassOtherIncomeRule implements TaxRuleContract
{
    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $minimumWage = $input->parameters->get('RO.MIN_WAGE')->asMoney('RON');
        $rate = $input->parameters->get('RO.CASS.RATE')->asDecimalRate();
        $wageMultiples = $this->wageMultiples($input);

        $income = Money::zero('RON');

        foreach ($input->facts as $fact) {
            $income = $income->add($fact->grossAmountRon);
        }

        /*
         * The highest threshold the income reaches, as money. Ascending, so the last one that fits wins —
         * and none fitting means no contribution, which is a real outcome rather than a failure to find
         * one.
         */
        $base = Money::zero('RON');
        $reached = null;
        $thresholds = [];

        foreach ($wageMultiples as $multiple) {
            $threshold = $this->wagesToMoney($minimumWage, $multiple);
            $thresholds['wages_' . $multiple] = $threshold->toString();

            if (! $income->isLessThan($threshold)) {
                $base = $threshold;
                $reached = $multiple;
            }
        }

        $contribution = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'qualifying_income' => $income->toString(),
                'minimum_wage' => $minimumWage->toString(),
                'rate' => $rate->toString(),
                ...$thresholds,
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_qualifying_income',
                    detail: ['fact_count' => (string) count($input->facts)],
                    result: $income->toString(),
                ),
                new CalculationStep(
                    operation: 'reach_threshold',
                    // Named even when nothing was reached, because "under the first threshold" is the
                    // answer in that case and a person reading this wants to see how far under.
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
            categoryCode: 'OTHER_INCOME',
            taxableBase: $base,
            rate: $rate,
            tax: $contribution,
            explanationKey: $reached === null
                ? 'tax.ro.pf.cass.other_income.below_threshold'
                : 'tax.ro.pf.cass.other_income.threshold_reached',
            trace: $trace,
        );
    }

    /**
     * The thresholds, in minimum wages, ascending.
     *
     * Sorted rather than trusted: the parameter is a JSON array somebody approved, and "the last one that
     * fits" is only the highest if the list is in order.
     *
     * @return list<int>
     */
    private function wageMultiples(TaxRuleInput $input): array
    {
        $value = $input->parameters->get('RO.CASS.OTHER_INCOME_BANDS_WAGES')->asJson();
        $wages = $value['wages'] ?? [];
        $multiples = [];

        foreach (is_array($wages) ? $wages : [] as $wage) {
            if (is_int($wage) && $wage > 0) {
                $multiples[] = $wage;
            }
        }

        sort($multiples);

        return $multiples;
    }

    /**
     * A whole number of minimum wages as money.
     *
     * By repeated addition rather than by a decimal multiplication: the multiple is a count, the wage is
     * an exact amount in minor units, and their product is exact — so there is no rounding decision to
     * make and none should be implied by asking for one.
     */
    private function wagesToMoney(Money $minimumWage, int $multiple): Money
    {
        $total = Money::zero('RON');

        for ($i = 0; $i < $multiple; $i++) {
            $total = $total->add($minimumWage);
        }

        return $total;
    }
}
