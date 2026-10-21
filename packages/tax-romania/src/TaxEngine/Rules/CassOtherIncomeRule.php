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
#[TaxRuleAttribute(
    code: 'RO.PF.CASS.OTHER_INCOME',
    version: '1.0.0',
    // Every category it is given except independent activity, which has its own base entirely — see the
    // class docblock. Listed rather than left empty so the one exclusion is stated where it can be checked.
    categories: [
        'DIVIDENDS_INTEREST',
        'CAPITAL_GAIN',
        'CRYPTO_GAIN',
        'OTHER_INCOME',
        'RENT',
    ],
    parameters: [
        CassOtherIncomeRule::MINIMUM_WAGE,
        CassOtherIncomeRule::RATE,
        CassOtherIncomeRule::BANDS,
    ],
)]
final class CassOtherIncomeRule implements TaxRuleContract
{
    public const string MINIMUM_WAGE = 'RO.MIN_WAGE';

    public const string RATE = 'RO.CASS.RATE';

    public const string BANDS = 'RO.CASS.OTHER_INCOME_BANDS_WAGES';

    /**
     * Independent activity is not "other income" and is excluded here.
     *
     * Its contribution has a different mechanism altogether — `RO.CASS.INDEPENDENT_BASE_WAGES` is
     * `{"floor": 6, "cap": 72}`, a band the income itself sits inside, not a step it is rounded up to. Summing
     * freelance income into these thresholds inflated this base and produced a figure for a regime this rule
     * does not implement, which is what {@see CassIndependentRule} is for.
     */
    private const string EXCLUDED_CATEGORY = 'FREELANCE';

    /** What the resulting line is about: a contribution across categories, not any single one of them. */
    private const string OUTCOME_CATEGORY = 'OTHER_INCOME';

    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $minimumWage = $input->parameters->get(self::MINIMUM_WAGE)->asMoney(CurrencyCode::BASE);
        $rate = $input->parameters->get(self::RATE)->asDecimalRate();
        $wageMultiples = WageThresholds::ascendingFrom($input->parameters->get(self::BANDS)->asJson());

        /*
         * Every fact, deliberately — unlike the income-tax rules beside it, which filter to their own category.
         * The thresholds apply to the *sum* of qualifying income, so narrowing here would be the bug rather than
         * the fix. What qualifies was already decided upstream: the producer excludes salary and pension, which
         * an employer withholds and declares.
         */
        $income = Money::zero(CurrencyCode::BASE);
        $counted = 0;

        foreach ($input->facts as $fact) {
            if ($fact->categoryCode === self::EXCLUDED_CATEGORY) {
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
                'qualifying_income' => $income->toString(),
                'minimum_wage' => $minimumWage->toString(),
                'rate' => $rate->toString(),
                ...$thresholds,
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_qualifying_income',
                    detail: ['fact_count' => (string) $counted],
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
            categoryCode: self::OUTCOME_CATEGORY,
            taxableBase: $base,
            rate: $rate,
            tax: $contribution,
            explanationKey: $reached === null
                ? 'tax.ro.pf.cass.other_income.below_threshold'
                : 'tax.ro.pf.cass.other_income.threshold_reached',
            trace: $trace,
        );
    }

}
