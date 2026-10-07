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
 * Income tax on gains from transfers of virtual currency.
 *
 * Self-declared, like rent and unlike a bank's interest: no exchange withholds Romanian tax, so the
 * figure is computed here and owed by the taxpayer.
 *
 * ## What a fact must already contain
 *
 * The *gain*, not the proceeds. A disposal's gain is proceeds minus the acquisition cost of what was
 * disposed of, and which cost that is depends on an inventory method applied across every earlier
 * purchase — which is bookkeeping over a portfolio, not arithmetic over one year's facts, and cannot be
 * done inside a rule that may not query anything. So whatever produces the fact computes the gain and
 * this taxes it. A fact whose gross is proceeds would produce a figure far too large, which is why
 * `subtypeCode` exists to distinguish them and why the producer's test is the one that matters here.
 *
 * ## What this deliberately does not do
 *
 * The law exempts small gains — a per-disposal threshold, and a yearly total below which nothing is
 * owed. **No approved parameter holds either number**, and `docs/AGENTS.md` rule 3 forbids inventing a
 * fiscal mapping in code, so this applies the rate to what it is given and nothing else. Adding the
 * exemption is a parameter to approve and two lines here — not a literal somebody remembers.
 */
#[TaxRuleAttribute(code: 'RO.PF.INCOME_TAX.CRYPTO_GAIN', version: '2026.1')]
final class CryptoGainIncomeTaxRule implements TaxRuleContract
{
    public function evaluate(TaxRuleInput $input): TaxRuleOutcome
    {
        $rate = $input->parameters->get('RO.INCOME_TAX.RATE')->asDecimalRate();

        $gains = Money::zero('RON');

        foreach ($input->facts as $fact) {
            $gains = $gains->add($fact->grossAmountRon);
        }

        /*
         * A loss-making year is not a negative tax. Gains and losses offset within the year — which the
         * producer has already done by the time these facts exist — and a total at or below zero owes
         * nothing rather than generating a credit.
         */
        $base = $gains->isPositive() ? $gains : Money::zero('RON');
        $tax = $base->multipliedByDecimal($rate, RoundingMode::HALF_UP);

        $trace = new CalculationTrace(
            ruleCode: $input->ruleVersion->ruleCode,
            ruleVersion: $input->ruleVersion->semanticVersion,
            inputs: [
                'net_gains' => $gains->toString(),
                'taxable_base' => $base->toString(),
                'rate' => $rate->toString(),
            ],
            steps: [
                new CalculationStep(
                    operation: 'sum_gains',
                    detail: ['fact_count' => (string) count($input->facts)],
                    result: $gains->toString(),
                ),
                new CalculationStep(
                    operation: 'floor_at_zero',
                    detail: ['was_negative' => $gains->isNegative() ? 'true' : 'false'],
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
            categoryCode: 'CRYPTO_GAIN',
            taxableBase: $base,
            rate: $rate,
            tax: $tax,
            explanationKey: 'tax.ro.pf.income_tax.crypto_gain',
            trace: $trace,
        );
    }
}
