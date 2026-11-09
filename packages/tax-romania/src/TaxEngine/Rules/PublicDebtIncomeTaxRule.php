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
use Kern\TaxRomania\TaxEngine\Exceptions\TaxRuleCannotComputeException;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * Income from public debt — Fidelis, Tezaur, a municipal bond: coupons and gains on a sale — owes no income
 * tax, because the law says so (art. 93 (1) a)) and a person approved that it does.
 *
 * Why a rule that computes nought exists at all: the exemption is a value read from the law, like a rate.
 * Without this rule nothing would notice the day the law stops saying it; with it, an assessment holding
 * such income needs `RO.INCOME_TAX.PUBLIC_DEBT_EXEMPT` approved, and an answer of no stops the assessment
 * rather than leave the income quietly untaxed — what would tax it then is a rule to write, not to guess.
 */
#[TaxRuleAttribute(
    code: 'RO.PF.INCOME_TAX.PUBLIC_DEBT',
    version: '1.0.0',
    categories: [PublicDebtIncomeTaxRule::CATEGORY],
    parameters: [PublicDebtIncomeTaxRule::EXEMPT],
)]
final class PublicDebtIncomeTaxRule implements TaxRuleContract
{
    public const string CATEGORY = 'PUBLIC_DEBT_INCOME';

    public const string EXEMPT = 'RO.INCOME_TAX.PUBLIC_DEBT_EXEMPT';

    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        if (($input->parameters->get(self::EXEMPT)->asJson()['value'] ?? null) !== true) {
            throw TaxRuleCannotComputeException::because('the approved RO.INCOME_TAX.PUBLIC_DEBT_EXEMPT says public debt is taxed, and no rule taxes it');
        }

        $income = Money::zero(CurrencyCode::BASE);
        $counted = 0;

        foreach ($input->facts as $fact) {
            if ($fact->categoryCode === self::CATEGORY) {
                $income = $income->add($fact->grossAmountRon);
                $counted++;
            }
        }

        $none = Money::zero(CurrencyCode::BASE);

        return new TaxRuleOutcome(
            taxCode: $input->ruleVersion->taxCode,
            categoryCode: self::CATEGORY,
            taxableBase: $none,
            rate: null,
            tax: $none,
            explanationKey: 'tax.ro.pf.income_tax.public_debt',
            trace: new CalculationTrace(
                ruleCode: $input->ruleVersion->ruleCode,
                ruleVersion: $input->ruleVersion->semanticVersion,
                inputs: ['income' => $income->toString(), 'exempt' => 'true'],
                steps: [new CalculationStep(operation: 'exempt_by_law', detail: ['fact_count' => (string) $counted], result: $none->toString())],
                sourceRefs: $input->ruleVersion->sourceSectionIds,
                result: $none->toString(),
            ),
        );
    }
}
