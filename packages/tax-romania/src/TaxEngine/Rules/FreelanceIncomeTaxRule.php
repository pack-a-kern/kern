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

/**
 * Income tax on independent activity, in the real-income system.
 *
 * Self-declared, like rent and crypto gains: nobody withholds it, so the figure is computed here and owed by the
 * taxpayer. The rate is the ordinary income tax rate.
 *
 * ## The base is income less deductible expenses, and the expenses have to reach the fact
 *
 * In the real-income system the tax is on profit, not on turnover — so the base is what came in less what the
 * activity cost. That subtraction is not this rule's to perform from the ledger: a rule may query nothing, and
 * deciding which of a person's payments were business expenses is a judgement. `TaxFactSnapshot` carries
 * `deductibleAmountRon` for exactly this, and whatever produces the fact is what fills it.
 *
 * **Today nothing fills it.** `BuildTaxFactsFromLedger` reads categorised income and has no notion of which
 * `EXPENSE` splits belong to an activity rather than to somebody's week, so the deductible arrives null and this
 * taxes the gross. That is stated in the trace rather than hidden: a freelancer with real costs will see a figure
 * that is too high, and the explanation says why.
 *
 * ## It does not implement the fixed-income system
 *
 * `norma de venit` taxes a notional amount set per activity and county, not what was earned — different maths and
 * a table of norms this installation does not hold. A taxpayer on that system is not served by this rule, and
 * `docs/AGENTS.md` rule 3 forbids inventing the mapping. Which system applies is also not recorded anywhere in
 * Kern, so nothing here can choose between them.
 */
#[TaxRuleAttribute(
    code: 'RO.PF.INCOME_TAX.FREELANCE',
    version: '1.0.0',
    categories: [FreelanceIncomeTaxRule::CATEGORY],
    parameters: [FreelanceIncomeTaxRule::RATE],
)]
final class FreelanceIncomeTaxRule implements TaxRuleContract
{
    public const string CATEGORY = 'FREELANCE';

    public const string RATE = 'RO.INCOME_TAX.RATE';

    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $rate = $input->parameters->get(self::RATE)->asDecimalRate();

        $gross = Money::zero(CurrencyCode::BASE);
        $deductible = Money::zero(CurrencyCode::BASE);
        $counted = 0;
        $anyDeductible = false;

        foreach ($input->facts as $fact) {
            if ($fact->categoryCode !== self::CATEGORY) {
                continue;
            }

            $gross = $gross->add($fact->grossAmountRon);
            $counted++;

            if ($fact->deductibleAmountRon !== null) {
                $deductible = $deductible->add($fact->deductibleAmountRon);
                $anyDeductible = true;
            }
        }

        /*
         * Expenses cannot exceed income here. A loss-making year is a real thing in this regime and carries
         * forward, which is a mechanism nothing in Kern implements — so the base is floored at zero and the loss
         * is not quietly turned into a credit against something else.
         */
        $base = $gross->subtract($deductible);
        $base = $base->isPositive() ? $base : Money::zero(CurrencyCode::BASE);

        $tax = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'gross_income' => $gross->toString(),
                'deductible_expenses' => $anyDeductible ? $deductible->toString() : 'none recorded',
                'rate' => $rate->toString(),
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_independent_income',
                    detail: ['fact_count' => (string) $counted],
                    result: $gross->toString(),
                ),
                new CalculationStep(
                    operation: 'subtract_deductible_expenses',
                    detail: [
                        'deductible' => $deductible->toString(),
                        // Said plainly, because the difference matters to whoever reads the figure: no expenses
                        // recorded is not the same claim as expenses of nothing.
                        'source' => $anyDeductible ? 'recorded on the facts' : 'none recorded',
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
            explanationKey: $anyDeductible
                ? 'tax.ro.pf.income_tax.freelance'
                : 'tax.ro.pf.income_tax.freelance.no_expenses',
            trace: $trace,
        );
    }
}
