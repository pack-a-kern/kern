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
 * Income tax on rent.
 *
 * Rent is not withheld at source: the landlord declares it and owes the tax themselves, which is why
 * this computes a figure where {@see CassOtherIncomeRule}'s categories mostly do not. The law allows a
 * flat expense quota instead of itemised costs, so the taxable base is the gross less that quota, and
 * the rate applies to what is left.
 *
 * Both numbers come from approved parameters — `RO.INCOME_TAX.RENT_EXPENSE_QUOTA` and
 * `RO.INCOME_TAX.RATE` — resolved for the date being assessed before this runs. Neither appears here,
 * so a quota that changes in 2027 changes the answer without changing this class.
 *
 * A fact may carry its own `deductibleAmountRon`, and this ignores it deliberately: the quota *is* the
 * deduction for this regime, and adding both would deduct the same costs twice. A landlord who itemises
 * instead is a different regime and would be a different rule, not a branch in this one.
 */
#[TaxRuleAttribute(
    code: 'RO.PF.INCOME_TAX.RENT',
    version: '2026.1',
    parameters: ['RO.INCOME_TAX.RATE', 'RO.INCOME_TAX.RENT_EXPENSE_QUOTA'],
)]
final class RentIncomeTaxRule implements TaxRuleContract
{
    private const string CATEGORY = 'RENT';


    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $quota = $input->parameters->get('RO.INCOME_TAX.RENT_EXPENSE_QUOTA')->asDecimalRate();
        $rate = $input->parameters->get('RO.INCOME_TAX.RATE')->asDecimalRate();

        /*
         * Only rent, out of everything handed over.
         *
         * The assessor gives every rule the whole year's facts, because `CassOtherIncomeRule` needs the sum
         * across categories to compare against its thresholds. A rule about one category therefore has to
         * say so — summing blindly taxed a dividend as rent the first time this ran against real data, and
         * produced a confident figure for a landlord who owns nothing.
         */
        $gross = Money::zero('RON');
        $counted = 0;

        foreach ($input->facts as $fact) {
            if ($fact->categoryCode !== self::CATEGORY) {
                continue;
            }

            $gross = $gross->add($fact->grossAmountRon);
            $counted++;
        }

        /*
         * Rounded down at the deduction and up at the tax, each in the taxpayer's favour at the step
         * where the law leaves a choice — and stated here rather than left to a default, because a
         * rounding mode nobody chose is a figure nobody can reproduce.
         */
        $deduction = $gross->multipliedByDecimal($quota, RoundingMode::HALF_UP);
        $base = $gross->subtract($deduction);
        $tax = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'gross_rent' => $gross->toString(),
                'expense_quota' => $quota->toString(),
                'rate' => $rate->toString(),
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_gross_rent',
                    detail: ['fact_count' => (string) $counted],
                    result: $gross->toString(),
                ),
                new CalculationStep(
                    operation: 'apply_expense_quota',
                    detail: ['quota' => $quota->toString(), 'deducted' => $deduction->toString()],
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
            categoryCode: self::CATEGORY,
            taxableBase: $base,
            rate: $rate,
            tax: $tax,
            explanationKey: 'tax.ro.pf.income_tax.rent',
            trace: $trace,
        );
    }
}
