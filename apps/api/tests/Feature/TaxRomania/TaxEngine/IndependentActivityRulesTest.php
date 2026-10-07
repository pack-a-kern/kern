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
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidParameterValueShapeException;
use Kern\TaxRomania\TaxEngine\Rules\CasIndependentRule;
use Kern\TaxRomania\TaxEngine\Rules\CassIndependentRule;
use Kern\TaxRomania\TaxEngine\Rules\FreelanceIncomeTaxRule;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * The three things owed on income from independent activity, and the two contributions are computed differently.
 *
 * That difference is the point of this file. The pension contribution steps up at thresholds — earn past one and
 * you pay a percentage of the threshold, so two different incomes can owe the same. The health contribution holds
 * the income itself between a floor and a cap. Both read the same income; neither is a variation of the other.
 *
 * A minimum wage of 1,000 throughout, so a reader can check every figure: thresholds at 12,000 and 24,000, a
 * floor of 6,000 and a cap of 72,000.
 */
function independentParameters(int $minimumWageMinor = 100000, int $capWages = 72): ResolvedParameterSet
{
    $validFrom = new DateTimeImmutable('2026-01-01');

    $parameter = static fn (string $code, FiscalParameterValueType $type, array $value): ResolvedFiscalParameter
        => new ResolvedFiscalParameter(
            parameterCode: $code,
            parameterVersionId: $code . '-version',
            valueType: $type,
            valueJson: $value,
            validFrom: $validFrom,
            validUntil: null,
            sourceSectionId: 'section-' . $code,
            status: RuleApprovalStatus::APPROVED,
        );

    return new ResolvedParameterSet([
        'RO.MIN_WAGE' => $parameter('RO.MIN_WAGE', FiscalParameterValueType::MONEY, ['amount_minor' => $minimumWageMinor, 'currency' => 'RON']),
        'RO.CAS.RATE' => $parameter('RO.CAS.RATE', FiscalParameterValueType::DECIMAL, ['value' => '0.25']),
        'RO.CAS.BASE_MIN_WAGES' => $parameter('RO.CAS.BASE_MIN_WAGES', FiscalParameterValueType::JSON, ['wages' => [12, 24]]),
        'RO.CASS.RATE' => $parameter('RO.CASS.RATE', FiscalParameterValueType::DECIMAL, ['value' => '0.10']),
        'RO.CASS.INDEPENDENT_BASE_WAGES' => $parameter('RO.CASS.INDEPENDENT_BASE_WAGES', FiscalParameterValueType::JSON, ['floor' => 6, 'cap' => $capWages]),
        'RO.INCOME_TAX.RATE' => $parameter('RO.INCOME_TAX.RATE', FiscalParameterValueType::DECIMAL, ['value' => '0.10']),
    ]);
}

/** @param list<array{0: int, 1: int|null}> $amounts gross and, where recorded, deductible expenses */
function independentInput(array $amounts, string $category = 'FREELANCE', int $capWages = 72): TaxRuleInput
{
    $facts = [];

    foreach ($amounts as $index => [$gross, $deductible]) {
        $facts[] = new TaxFactSnapshot(
            id: 'fact-' . $index,
            categoryCode: $category,
            subtypeCode: null,
            recognitionDate: new DateTimeImmutable('2026-06-01'),
            cashDate: null,
            earnedAt: null,
            receivedAt: null,
            paidAt: null,
            distributedAt: null,
            contractDate: null,
            transactionDate: null,
            grossAmountRon: Money::ofMinorUnits($gross, 'RON'),
            deductibleAmountRon: $deductible === null ? null : Money::ofMinorUnits($deductible, 'RON'),
            withheldTaxRon: null,
            status: TaxFactStatus::CONFIRMED,
        );
    }

    return new TaxRuleInput(
        fiscalYear: 2026,
        asOf: new DateTimeImmutable('2026-06-01'),
        facts: $facts,
        parameters: independentParameters(capWages: $capWages),
        ruleVersion: new ResolvedTaxRuleVersion(
            id: 'version-1',
            ruleCode: 'RO.PF.TEST',
            taxCode: 'TEST',
            semanticVersion: '1.0.0',
            validFrom: new DateTimeImmutable('2018-01-01'),
            validUntil: null,
            implementationClass: null,
            configJson: [],
            status: RuleApprovalStatus::CANDIDATE,
            sourceSectionIds: ['section-1'],
        ),
    );
}

it('charges the pension contribution on the threshold reached, not on the income', function (): void {
    // 15,000 reaches the 12-wage threshold, so 25% of 12,000.
    $outcome = (new CasIndependentRule())->evaluate(independentInput([[1500000, null]]));

    expect($outcome->taxableBase->toString())->toBe('12000.00 RON')
        ->and($outcome->tax->toString())->toBe('3000.00 RON')
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.cas.independent.threshold_reached');
});

it('owes no pension contribution below the first threshold', function (): void {
    $outcome = (new CasIndependentRule())->evaluate(independentInput([[1199999, null]]));

    expect($outcome->tax->isZero())->toBeTrue()
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.cas.independent.below_threshold');
});

it('holds the health contribution base at the floor when income is under it', function (): void {
    // 2,000 of income, a floor of 6,000: the base is the floor, so 600 rather than 200.
    $outcome = (new CassIndependentRule())->evaluate(independentInput([[200000, null]]));

    expect($outcome->taxableBase->toString())->toBe('6000.00 RON')
        ->and($outcome->tax->toString())->toBe('600.00 RON')
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.cass.independent.at_floor');
});

it('charges the health contribution on the income itself between floor and cap', function (): void {
    // Unlike the pension contribution beside it, which would round this up to a threshold.
    $outcome = (new CassIndependentRule())->evaluate(independentInput([[2000000, null]]));

    expect($outcome->taxableBase->toString())->toBe('20000.00 RON')
        ->and($outcome->tax->toString())->toBe('2000.00 RON')
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.cass.independent.on_income');
});

it('caps the health contribution base, and the cap moves by year', function (): void {
    // 100,000 against a 72-wage cap is held at 72,000; the same income in a 60-wage year is held at 60,000.
    expect((new CassIndependentRule())->evaluate(independentInput([[10000000, null]]))->tax->toString())
        ->toBe('7200.00 RON')
        ->and((new CassIndependentRule())->evaluate(independentInput([[10000000, null]], capWages: 60))->tax->toString())
        ->toBe('6000.00 RON');
});

it('owes no health contribution without independent income, rather than charging the floor', function (): void {
    // The floor raises a base; it does not create one. Somebody with no such activity has not earned under it.
    $outcome = (new CassIndependentRule())->evaluate(independentInput([]));

    expect($outcome->tax->isZero())->toBeTrue()
        ->and($outcome->taxableBase->isZero())->toBeTrue()
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.cass.independent.no_income');
});

it('refuses a cap below its floor rather than picking one of them', function (): void {
    $input = independentInput([[2000000, null]], capWages: 3);

    expect(fn () => (new CassIndependentRule())->evaluate($input))
        ->toThrow(InvalidParameterValueShapeException::class);
});

it('taxes independent income less the expenses recorded against it', function (): void {
    // 30,000 earned, 8,000 of recorded expenses: tax on 22,000.
    $outcome = (new FreelanceIncomeTaxRule())->evaluate(independentInput([[3000000, 800000]]));

    expect($outcome->taxableBase->toString())->toBe('22000.00 RON')
        ->and($outcome->tax->toString())->toBe('2200.00 RON')
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.income_tax.freelance');
});

it('says so when it taxes the gross because no expenses are recorded', function (): void {
    /*
     * Nothing fills `deductibleAmountRon` today — the producer reads categorised income and has no notion of
     * which expense splits belong to an activity. So a freelancer with real costs sees a figure that is too high,
     * and the explanation and the trace both say why rather than letting it look settled.
     */
    $outcome = (new FreelanceIncomeTaxRule())->evaluate(independentInput([[3000000, null]]));

    expect($outcome->taxableBase->toString())->toBe('30000.00 RON')
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.income_tax.freelance.no_expenses')
        ->and($outcome->trace->inputs['deductible_expenses'])->toBe('none recorded');
});

it('floors a loss-making year at zero rather than crediting it elsewhere', function (): void {
    // Losses carry forward in this regime, which nothing in Kern implements — so the base stops at zero instead
    // of becoming a credit against something it was never set against.
    $outcome = (new FreelanceIncomeTaxRule())->evaluate(independentInput([[1000000, 1500000]]));

    expect($outcome->taxableBase->isZero())->toBeTrue()
        ->and($outcome->tax->isZero())->toBeTrue();
});

it('each of the three reads only independent activity', function (): void {
    // The assessor hands every rule the whole year. A dividend is not independent income, and none of these three
    // may treat it as such.
    $dividends = independentInput([[9900000, null]], category: 'DIVIDENDS_INTEREST');

    expect((new CasIndependentRule())->evaluate($dividends)->tax->isZero())->toBeTrue()
        ->and((new CassIndependentRule())->evaluate($dividends)->tax->isZero())->toBeTrue()
        ->and((new FreelanceIncomeTaxRule())->evaluate($dividends)->tax->isZero())->toBeTrue();
});
