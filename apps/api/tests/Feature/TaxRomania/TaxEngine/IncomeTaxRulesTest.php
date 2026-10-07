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
use Kern\TaxRomania\TaxEngine\Rules\CryptoGainIncomeTaxRule;
use Kern\TaxRomania\TaxEngine\Rules\RentIncomeTaxRule;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * The two income taxes a person owes themselves, rather than having had withheld.
 *
 * Rates and quotas are supplied by the test, not read from this year's approved rows: the real ones change
 * by year, and a test asserting them would be asserting the law instead of the rule and would need editing
 * every January. A rate of 10% on a quota of 20% keeps the arithmetic checkable by eye.
 */
function incomeTaxParameters(string $rate = '0.1000', string $rentQuota = '0.2000'): ResolvedParameterSet
{
    $validFrom = new DateTimeImmutable('2026-01-01');

    return new ResolvedParameterSet([
        'RO.INCOME_TAX.RATE' => new ResolvedFiscalParameter(
            parameterCode: 'RO.INCOME_TAX.RATE',
            parameterVersionId: 'rate-version',
            valueType: FiscalParameterValueType::DECIMAL,
            valueJson: ['value' => $rate],
            validFrom: $validFrom,
            validUntil: null,
            sourceSectionId: 'section-rate',
            status: RuleApprovalStatus::APPROVED,
        ),
        'RO.INCOME_TAX.RENT_EXPENSE_QUOTA' => new ResolvedFiscalParameter(
            parameterCode: 'RO.INCOME_TAX.RENT_EXPENSE_QUOTA',
            parameterVersionId: 'quota-version',
            valueType: FiscalParameterValueType::DECIMAL,
            valueJson: ['value' => $rentQuota],
            validFrom: $validFrom,
            validUntil: null,
            sourceSectionId: 'section-quota',
            status: RuleApprovalStatus::APPROVED,
        ),
    ]);
}

/** @param list<int> $grossMinorAmounts */
function incomeTaxInput(
    string $categoryCode,
    array $grossMinorAmounts,
    string $implementationClass,
    string $ruleCode,
    string $rate = '0.1000',
): TaxRuleInput {
    $facts = [];

    foreach ($grossMinorAmounts as $index => $minor) {
        $facts[] = new TaxFactSnapshot(
            id: 'fact-' . $index,
            categoryCode: $categoryCode,
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
            // Set on purpose: the rent rule must ignore it, because the quota *is* the deduction and
            // applying both would deduct the same costs twice.
            deductibleAmountRon: Money::ofMinorUnits(50000, 'RON'),
            withheldTaxRon: null,
            status: TaxFactStatus::CONFIRMED,
        );
    }

    return new TaxRuleInput(
        fiscalYear: 2026,
        asOf: new DateTimeImmutable('2026-06-01'),
        facts: $facts,
        parameters: incomeTaxParameters(rate: $rate),
        ruleVersion: new ResolvedTaxRuleVersion(
            id: 'version-1',
            ruleCode: $ruleCode,
            taxCode: 'INCOME_TAX',
            semanticVersion: '2026.1',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validUntil: null,
            implementationClass: $implementationClass,
            configJson: [],
            status: RuleApprovalStatus::APPROVED,
            sourceSectionIds: ['section-rate'],
        ),
    );
}

/** @param list<int> $gross */
function rentInput(array $gross, string $rate = '0.1000'): TaxRuleInput
{
    return incomeTaxInput('RENT', $gross, RentIncomeTaxRule::class, 'RO.PF.INCOME_TAX.RENT', $rate);
}

/** @param list<int> $gross */
function cryptoInput(array $gross): TaxRuleInput
{
    return incomeTaxInput('CRYPTO_GAIN', $gross, CryptoGainIncomeTaxRule::class, 'RO.PF.INCOME_TAX.CRYPTO_GAIN');
}

it('taxes rent on the gross less the expense quota', function (): void {
    // 12,000 gross, 20% quota → a base of 9,600, taxed at 10% → 960.
    $outcome = (new RentIncomeTaxRule())->evaluate(rentInput([1200000]));

    expect($outcome->taxableBase->toString())->toBe('9600.00 RON')
        ->and($outcome->tax->toString())->toBe('960.00 RON')
        ->and($outcome->categoryCode)->toBe('RENT');
});

it('ignores a deductible amount on the fact, because the quota is the deduction', function (): void {
    // Each fact carries a 500 deductible. Honouring it as well would deduct the landlord's costs twice —
    // once as the flat quota the law offers instead of itemising, and once itemised.
    $outcome = (new RentIncomeTaxRule())->evaluate(rentInput([1200000]));

    expect($outcome->taxableBase->toString())->toBe('9600.00 RON');
});

it('shows the quota and the rate as separate steps, so an explanation can walk them', function (): void {
    $outcome = (new RentIncomeTaxRule())->evaluate(rentInput([1200000]));

    $operations = array_map(static fn ($step): string => $step->operation, $outcome->trace->steps);

    expect($operations)->toBe(['sum_gross_rent', 'apply_expense_quota', 'multiply_by_rate'])
        ->and($outcome->trace->inputs['expense_quota'])->toBe('0.2000')
        ->and($outcome->trace->inputs['gross_rent'])->toBe('12000.00 RON');
});

it('follows a changed rate without changing the rule', function (): void {
    // The same 12,000 of rent at a 16% rate. Nothing in the class knows either number.
    $outcome = (new RentIncomeTaxRule())->evaluate(rentInput([1200000], rate: '0.1600'));

    expect($outcome->tax->toString())->toBe('1536.00 RON');
});

it('owes nothing on rent of nothing', function (): void {
    $outcome = (new RentIncomeTaxRule())->evaluate(rentInput([]));

    expect($outcome->tax->isZero())->toBeTrue()
        ->and($outcome->trace->steps[0]->detail['fact_count'])->toBe('0');
});

it('taxes a crypto gain at the rate, with no quota', function (): void {
    // 5,000 of gain at 10%. No expense quota exists for this income, and inventing one would be a fiscal
    // rule nobody approved.
    $outcome = (new CryptoGainIncomeTaxRule())->evaluate(cryptoInput([500000]));

    expect($outcome->taxableBase->toString())->toBe('5000.00 RON')
        ->and($outcome->tax->toString())->toBe('500.00 RON');
});

it('owes nothing on a losing year rather than generating a credit', function (): void {
    // Gains and losses offset within the year before they reach here; a negative total is zero tax, not a
    // refund the engine invented.
    $outcome = (new CryptoGainIncomeTaxRule())->evaluate(cryptoInput([500000, -800000]));

    expect($outcome->tax->isZero())->toBeTrue()
        ->and($outcome->taxableBase->isZero())->toBeTrue()
        ->and($outcome->trace->steps[1]->detail['was_negative'])->toBe('true');
});

it('nets gains against losses before taxing', function (): void {
    $outcome = (new CryptoGainIncomeTaxRule())->evaluate(cryptoInput([800000, -300000]));

    expect($outcome->taxableBase->toString())->toBe('5000.00 RON')
        ->and($outcome->tax->toString())->toBe('500.00 RON');
});

it('taxes only its own category, out of everything the assessor hands it', function (): void {
    /*
     * The bug this file exists to prevent, found by running an assessment against real data: the assessor
     * gives every rule the whole year's facts, because `CassOtherIncomeRule` needs the sum across categories.
     * Summing blindly produced a confident rent figure for somebody who owns no property, from a dividend.
     */
    $mixed = incomeTaxInput('RENT', [1200000], RentIncomeTaxRule::class, 'RO.PF.INCOME_TAX.RENT');

    $withIntruders = new TaxRuleInput(
        fiscalYear: $mixed->fiscalYear,
        asOf: $mixed->asOf,
        facts: [
            ...$mixed->facts,
            ...incomeTaxInput('DIVIDENDS_INTEREST', [9900000], RentIncomeTaxRule::class, 'RO.PF.INCOME_TAX.RENT')->facts,
            ...incomeTaxInput('CRYPTO_GAIN', [5000000], RentIncomeTaxRule::class, 'RO.PF.INCOME_TAX.RENT')->facts,
        ],
        parameters: $mixed->parameters,
        ruleVersion: $mixed->ruleVersion,
    );

    // The rent is still 12,000, and the 99,000 dividend and 50,000 crypto gain are not rent.
    $outcome = (new RentIncomeTaxRule())->evaluate($withIntruders);

    expect($outcome->taxableBase->toString())->toBe('9600.00 RON')
        ->and($outcome->tax->toString())->toBe('960.00 RON')
        ->and($outcome->trace->steps[0]->detail['fact_count'])->toBe('1');
});

it('counts no crypto gain in a year of dividends', function (): void {
    $input = incomeTaxInput('DIVIDENDS_INTEREST', [9900000], CryptoGainIncomeTaxRule::class, 'RO.PF.INCOME_TAX.CRYPTO_GAIN');

    $outcome = (new CryptoGainIncomeTaxRule())->evaluate($input);

    expect($outcome->tax->isZero())->toBeTrue()
        ->and($outcome->trace->steps[0]->detail['fact_count'])->toBe('0');
});
