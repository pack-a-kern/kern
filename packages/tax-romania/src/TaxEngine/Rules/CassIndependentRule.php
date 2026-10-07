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
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidParameterValueShapeException;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;
use Kern\TaxRomania\TaxEngine\ValueObjects\WageThresholds;

/**
 * Health contribution on income from independent activity.
 *
 * ## Not a step — a floor and a cap
 *
 * `RO.CASS.INDEPENDENT_BASE_WAGES` is `{"floor": 6, "cap": 72}`, and that is a different mechanism from the one
 * {@see CassOtherIncomeRule} implements. There the contribution is a percentage of whichever threshold the
 * income *reached*, so seven minimum wages and eleven pay the same. Here the income itself is the base, held
 * between a minimum and a maximum: earn less than the floor and you pay on the floor, earn more than the cap and
 * you pay on the cap, and in between you pay on what you earned.
 *
 * Conflating the two is not an approximation, it is a different answer — which is what happened while freelance
 * income was being summed into the other-income thresholds, and why that rule now excludes it.
 *
 * The cap moves: 60 wages in 2025, 72 in 2026. Which is the point of it being a parameter.
 *
 * ## The floor applies to income, not to its absence
 *
 * No independent income at all owes nothing — the floor is a minimum base for somebody who had income, not a
 * charge for having none. A person with no such activity is not a person who earned under the floor.
 */
#[TaxRuleAttribute(
    code: 'RO.PF.CASS.INDEPENDENT',
    version: '1.0.0',
    categories: [CassIndependentRule::CATEGORY],
    parameters: [
        CassIndependentRule::MINIMUM_WAGE,
        CassIndependentRule::RATE,
        CassIndependentRule::BOUNDS,
    ],
)]
final class CassIndependentRule implements TaxRuleContract
{
    public const string CATEGORY = 'FREELANCE';

    public const string MINIMUM_WAGE = 'RO.MIN_WAGE';

    public const string RATE = 'RO.CASS.RATE';

    public const string BOUNDS = 'RO.CASS.INDEPENDENT_BASE_WAGES';

    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $minimumWage = $input->parameters->get(self::MINIMUM_WAGE)->asMoney('RON');
        $rate = $input->parameters->get(self::RATE)->asDecimalRate();
        $bounds = $input->parameters->get(self::BOUNDS)->asJson();

        $floorWages = $bounds['floor'] ?? null;
        $capWages = $bounds['cap'] ?? null;

        if (! is_int($floorWages) || ! is_int($capWages) || $floorWages > $capWages) {
            // Refused rather than guessed: a cap below its floor describes no regime, and picking one of them
            // would invent the taxpayer's base.
            throw InvalidParameterValueShapeException::forParameter(
                self::BOUNDS,
                'value_json must hold integer "floor" and "cap" wage multiples, with floor <= cap',
            );
        }

        $income = Money::zero('RON');
        $counted = 0;

        foreach ($input->facts as $fact) {
            if ($fact->categoryCode !== self::CATEGORY) {
                continue;
            }

            $income = $income->add($fact->grossAmountRon);
            $counted++;
        }

        $floor = WageThresholds::wagesToMoney($minimumWage, $floorWages);
        $cap = WageThresholds::wagesToMoney($minimumWage, $capWages);

        // No independent income is not income below the floor. The floor raises a base; it does not create one.
        $base = $income->isPositive() ? $income->max($floor)->min($cap) : Money::zero('RON');
        $contribution = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'independent_income' => $income->toString(),
                'minimum_wage' => $minimumWage->toString(),
                'rate' => $rate->toString(),
                'floor_wages' => (string) $floorWages,
                'cap_wages' => (string) $capWages,
                'floor' => $floor->toString(),
                'cap' => $cap->toString(),
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_independent_income',
                    detail: ['fact_count' => (string) $counted],
                    result: $income->toString(),
                ),
                new CalculationStep(
                    operation: 'hold_between_floor_and_cap',
                    detail: [
                        'floor' => $floor->toString(),
                        'cap' => $cap->toString(),
                        'held_at' => $this->describeHold($income, $floor, $cap),
                    ],
                    result: $base->toString(),
                ),
                new CalculationStep(
                    operation: 'multiply_base_by_rate',
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
            explanationKey: $this->explanationFor($income, $floor, $cap),
            trace: $trace,
        );
    }

    /** Which of the three the base came from, so an explanation can say why it is not the income. */
    private function describeHold(Money $income, Money $floor, Money $cap): string
    {
        if (! $income->isPositive()) {
            return 'no_income';
        }

        if ($income->isLessThan($floor)) {
            return 'floor';
        }

        return $income->isGreaterThan($cap) ? 'cap' : 'income';
    }

    private function explanationFor(Money $income, Money $floor, Money $cap): string
    {
        return match ($this->describeHold($income, $floor, $cap)) {
            'no_income' => 'tax.ro.pf.cass.independent.no_income',
            'floor' => 'tax.ro.pf.cass.independent.at_floor',
            'cap' => 'tax.ro.pf.cass.independent.at_cap',
            default => 'tax.ro.pf.cass.independent.on_income',
        };
    }
}
