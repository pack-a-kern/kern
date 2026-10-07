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
    version: '1.0.0',
    categories: [RentIncomeTaxRule::CATEGORY],
    parameters: [RentIncomeTaxRule::RATE, RentIncomeTaxRule::QUOTA],
)]
final class RentIncomeTaxRule implements TaxRuleContract
{
    public const string CATEGORY = 'RENT';

    public const string RATE = 'RO.INCOME_TAX.RATE';

    public const string QUOTA = 'RO.INCOME_TAX.RENT_EXPENSE_QUOTA';


    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $rate = $input->parameters->get(self::RATE)->asDecimalRate();

        /*
         * A year whose law sets no expense allowance deducts nothing.
         *
         * 2023 is such a year: Romania's rent expense quota was 40% through 2022 and 20% from 2024, and the 2023
         * law states none — a fact somebody read and recorded, which the screen shows as a dash. The engine used
         * to fail that year as though the value were merely unloaded.
         *
         * Decided here rather than in the resolver because it is a question about *this* tax: no allowance means
         * the whole rent is taxable, and the same absence on a rate would mean something else entirely. A rule
         * that cannot proceed without a parameter the law omits should still refuse, which `get()` does.
         */
        $quotaStatedByNoLaw = ! $input->parameters->has(self::QUOTA)
            && $input->parameters->statedByNoLaw(self::QUOTA);

        $quota = $quotaStatedByNoLaw ? null : $input->parameters->get(self::QUOTA)->asDecimalRate();

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
        $deduction = $quota === null
            ? Money::zero('RON')
            : $gross->multipliedByDecimal($quota, RoundingMode::HALF_UP);
        $base = $gross->subtract($deduction);
        $tax = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'gross_rent' => $gross->toString(),
                // Named as what it is when the law sets none, rather than printed as a zero somebody might read
                // as a rate that happened to be nil.
                'expense_quota' => $quota === null ? 'not in law' : $quota->toString(),
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
                    detail: [
                        'quota' => $quota === null ? 'not in law' : $quota->toString(),
                        'deducted' => $deduction->toString(),
                    ],
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
            explanationKey: $quota === null
                ? 'tax.ro.pf.income_tax.rent.no_allowance'
                : 'tax.ro.pf.income_tax.rent',
            trace: $trace,
        );
    }
}
