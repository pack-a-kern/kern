<?php

declare(strict_types=1);

use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedParameterSet;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxFactSnapshot;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleInput;
use Kern\TaxRomania\TaxEngine\Enums\FiscalParameterValueType;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Enums\TaxFactStatus;
use Kern\TaxRomania\TaxEngine\Rules\CassOtherIncomeRule;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * The health contribution on income other than salary.
 *
 * What is being tested is the *shape* of the answer — a step, not a percentage — using thresholds and a
 * minimum wage supplied by the test rather than read from anywhere. The real values live in approved
 * `fiscal_parameter_versions` rows and change by year; a test that hardcoded this year's would be
 * asserting the law rather than the rule, and would need editing every January for no reason.
 *
 * Round numbers throughout, for the same reason: 1,000 per wage makes the thresholds 6,000 / 12,000 /
 * 24,000, and a reader can check every assertion in their head.
 */
function cassParameters(int $minimumWageMinor = 100000, string $rate = '0.1000'): ResolvedParameterSet
{
    $validFrom = new DateTimeImmutable('2026-01-01');

    return new ResolvedParameterSet([
        'RO.MIN_WAGE' => new ResolvedFiscalParameter(
            parameterCode: 'RO.MIN_WAGE',
            parameterVersionId: 'min-wage-version',
            valueType: FiscalParameterValueType::MONEY,
            valueJson: ['amount_minor' => $minimumWageMinor, 'currency_code' => 'RON'],
            validFrom: $validFrom,
            validUntil: null,
            sourceSectionId: 'section-min-wage',
            status: RuleApprovalStatus::APPROVED,
        ),
        'RO.CASS.RATE' => new ResolvedFiscalParameter(
            parameterCode: 'RO.CASS.RATE',
            parameterVersionId: 'cass-rate-version',
            valueType: FiscalParameterValueType::DECIMAL,
            valueJson: ['value' => $rate],
            validFrom: $validFrom,
            validUntil: null,
            sourceSectionId: 'section-cass-rate',
            status: RuleApprovalStatus::APPROVED,
        ),
        'RO.CASS.OTHER_INCOME_BANDS_WAGES' => new ResolvedFiscalParameter(
            parameterCode: 'RO.CASS.OTHER_INCOME_BANDS_WAGES',
            parameterVersionId: 'bands-version',
            valueType: FiscalParameterValueType::JSON,
            // Deliberately out of order: the rule sorts, because "the last one that fits" is only the
            // highest if the list is ordered, and the list is whatever somebody approved.
            valueJson: ['wages' => [12, 6, 24]],
            validFrom: $validFrom,
            validUntil: null,
            sourceSectionId: 'section-bands',
            status: RuleApprovalStatus::APPROVED,
        ),
    ]);
}

function cassRuleVersion(): ResolvedTaxRuleVersion
{
    return new ResolvedTaxRuleVersion(
        id: 'cass-version-1',
        ruleCode: 'RO.PF.CASS.OTHER_INCOME',
        taxCode: 'CASS',
        semanticVersion: '2026.1',
        validFrom: new DateTimeImmutable('2026-01-01'),
        validUntil: null,
        implementationClass: CassOtherIncomeRule::class,
        configJson: [],
        status: RuleApprovalStatus::APPROVED,
        sourceSectionIds: ['section-bands'],
    );
}

/** @param list<int> $grossMinorAmounts */
function cassInput(array $grossMinorAmounts, int $minimumWageMinor = 100000): TaxRuleInput
{
    $facts = [];

    foreach ($grossMinorAmounts as $index => $minor) {
        $facts[] = new TaxFactSnapshot(
            id: 'fact-' . $index,
            categoryCode: 'DIVIDENDS_INTEREST',
            subtypeCode: null,
            recognitionDate: new DateTimeImmutable('2026-06-01'),
            cashDate: null,
            earnedAt: null,
            receivedAt: null,
            paidAt: null,
            distributedAt: null,
            contractDate: null,
            transactionDate: null,
            grossAmountRon: Money::ofMinorUnits($minor, 'RON'),
            deductibleAmountRon: null,
            withheldTaxRon: null,
            status: TaxFactStatus::CONFIRMED,
        );
    }

    return new TaxRuleInput(
        fiscalYear: 2026,
        asOf: new DateTimeImmutable('2026-06-01'),
        facts: $facts,
        parameters: cassParameters(minimumWageMinor: $minimumWageMinor),
        ruleVersion: cassRuleVersion(),
    );
}

it('owes nothing below the first threshold, and says how far below', function (): void {
    // 5,999.99 against a 6,000 threshold. The one leu that matters.
    $outcome = (new CassOtherIncomeRule())->evaluate(cassInput([599999]));

    expect($outcome->tax->isZero())->toBeTrue()
        ->and($outcome->taxableBase->isZero())->toBeTrue()
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.cass.other_income.below_threshold');

    // The trace still names every threshold, because "under the first one" is only useful alongside what
    // the first one was.
    expect($outcome->trace->inputs)->toHaveKey('wages_6')
        ->and($outcome->trace->inputs['qualifying_income'])->toBe('5999.99 RON');
});

it('charges the threshold reached rather than a share of the income', function (): void {
    // 7,000 of income reaches the 6,000 threshold. The contribution is 10% of 6,000 — not of 7,000.
    $outcome = (new CassOtherIncomeRule())->evaluate(cassInput([700000]));

    expect($outcome->taxableBase->toString())->toBe('6000.00 RON')
        ->and($outcome->tax->toString())->toBe('600.00 RON')
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.cass.other_income.threshold_reached');
});

it('charges two very different incomes the same, which is the thing that surprises people', function (): void {
    // 7,000 and 11,000 both land in the 6-wage band. This is the behaviour `/taxes/explain` exists for.
    $lower = (new CassOtherIncomeRule())->evaluate(cassInput([700000]));
    $higher = (new CassOtherIncomeRule())->evaluate(cassInput([1100000]));

    expect($higher->tax->toString())->toBe($lower->tax->toString())
        ->and($higher->tax->toString())->toBe('600.00 RON');
});

it('steps up at each threshold, exactly at it', function (): void {
    foreach ([[1200000, '1200.00 RON'], [2400000, '2400.00 RON'], [9900000, '2400.00 RON']] as [$income, $expected]) {
        // The last is 99,000 — far above the top threshold, which caps rather than continuing.
        expect((new CassOtherIncomeRule())->evaluate(cassInput([$income]))->tax->toString())->toBe($expected);
    }
});

it('totals every category before comparing, because the thresholds apply to the sum', function (): void {
    // Four incomes of 2,000 are under the threshold one at a time and over it together. A rule per
    // category could not see this, which is why there is one rule for all of them.
    $outcome = (new CassOtherIncomeRule())->evaluate(cassInput([200000, 200000, 200000, 200000]));

    expect($outcome->trace->inputs['qualifying_income'])->toBe('8000.00 RON')
        ->and($outcome->tax->toString())->toBe('600.00 RON');
});

it('follows the minimum wage, without the thresholds changing', function (): void {
    // Same 7,000 of income, same [6,12,24] bands, a minimum wage of 1,200 instead of 1,000 — so the first
    // threshold becomes 7,200 and the income no longer reaches it. The year's answer changes because an
    // approved value changed, not because this class did.
    $outcome = (new CassOtherIncomeRule())->evaluate(cassInput([700000], minimumWageMinor: 120000));

    expect($outcome->tax->isZero())->toBeTrue();
});

it('records the rule version and the sources the figure came from', function (): void {
    $outcome = (new CassOtherIncomeRule())->evaluate(cassInput([700000]));

    expect($outcome->trace->ruleCode)->toBe('RO.PF.CASS.OTHER_INCOME')
        ->and($outcome->trace->ruleVersion)->toBe('2026.1')
        ->and($outcome->trace->sourceRefs)->toBe(['section-bands'])
        ->and($outcome->trace->result)->toBe('600.00 RON');
});

it('leaves independent activity out, because that has its own base entirely', function (): void {
    /*
     * `RO.CASS.INDEPENDENT_BASE_WAGES` is `{"floor": 6, "cap": 72}` — a band the income sits inside — while these
     * thresholds are steps the income is rounded up to. Two mechanisms, and this rule implements one.
     *
     * Summing freelance income here inflated this base and produced a figure under a regime this class does not
     * implement, which is what `CassIndependentRule` is for. Found by adding a freelance transaction and getting
     * a confident answer.
     */
    $input = cassInput([700000]);

    $withFreelance = new TaxRuleInput(
        fiscalYear: $input->fiscalYear,
        asOf: $input->asOf,
        facts: [
            ...$input->facts,
            new TaxFactSnapshot(
                id: 'freelance-fact',
                categoryCode: 'FREELANCE',
                subtypeCode: null,
                recognitionDate: new DateTimeImmutable('2026-06-01'),
                cashDate: null,
                earnedAt: null,
                receivedAt: null,
                paidAt: null,
                distributedAt: null,
                contractDate: null,
                transactionDate: null,
                grossAmountRon: Money::ofMinorUnits(9900000, 'RON'),
                deductibleAmountRon: null,
                withheldTaxRon: null,
                status: TaxFactStatus::CONFIRMED,
            ),
        ],
        parameters: $input->parameters,
        ruleVersion: $input->ruleVersion,
    );

    $outcome = (new CassOtherIncomeRule())->evaluate($withFreelance);

    // The 99,000 of freelance income is not here: 7,000 still reaches only the 6-wage threshold.
    expect($outcome->trace->inputs['qualifying_income'])->toBe('7000.00 RON')
        ->and($outcome->tax->toString())->toBe('600.00 RON')
        ->and($outcome->trace->steps[0]->detail['fact_count'])->toBe('1');
});
