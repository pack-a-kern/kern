<?php

declare(strict_types=1);

use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedParameterSet;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleInput;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidRuleImplementationException;
use Kern\TaxRomania\TaxEngine\Exceptions\MissingRequiredParameterException;
use Kern\TaxRomania\TaxEngine\Services\RuleInvoker;
use Tests\Fixtures\TaxEngine\Rules\SyntheticPercentageOfBaseRule;

function makeResolvedTaxRuleVersionFixture(?string $implementationClass): ResolvedTaxRuleVersion
{
    return new ResolvedTaxRuleVersion(
        id: 'version-1',
        ruleCode: 'RO.TEST.INVOKER',
        taxCode: 'SYNTHETIC_TAX',
        semanticVersion: '0.0.1',
        validFrom: new DateTimeImmutable('2026-01-01'),
        validUntil: null,
        implementationClass: $implementationClass,
        configJson: [],
        status: RuleApprovalStatus::CANDIDATE,
        sourceSectionIds: [],
    );
}

it('invokes a conforming rule and returns its outcome', function (): void {
    $ruleVersion = makeResolvedTaxRuleVersionFixture(SyntheticPercentageOfBaseRule::class);
    $input = new TaxRuleInput(
        fiscalYear: 2026,
        asOf: new DateTimeImmutable('2026-06-01'),
        facts: [],
        parameters: new ResolvedParameterSet([]),
        ruleVersion: $ruleVersion,
    );

    // The synthetic rule reads RO.TEST.SYNTHETIC_RATE — omit it to prove RuleInvoker itself,
    // not the rule's own logic, is under test here: expect the rule's own MissingRequiredParameterException.
    expect(fn () => (new RuleInvoker())->invoke($ruleVersion, $input))
        ->toThrow(MissingRequiredParameterException::class);
});

it('throws when implementation_class is missing', function (): void {
    $ruleVersion = makeResolvedTaxRuleVersionFixture(null);
    $input = new TaxRuleInput(2026, new DateTimeImmutable(), [], new ResolvedParameterSet([]), $ruleVersion);

    expect(fn () => (new RuleInvoker())->invoke($ruleVersion, $input))
        ->toThrow(InvalidRuleImplementationException::class);
});

it('throws when implementation_class does not exist', function (): void {
    $ruleVersion = makeResolvedTaxRuleVersionFixture('Kern\\TaxRomania\\TaxEngine\\NoSuchClassAtAll');
    $input = new TaxRuleInput(2026, new DateTimeImmutable(), [], new ResolvedParameterSet([]), $ruleVersion);

    expect(fn () => (new RuleInvoker())->invoke($ruleVersion, $input))
        ->toThrow(InvalidRuleImplementationException::class);
});

it('throws when implementation_class does not implement TaxRuleContract', function (): void {
    $ruleVersion = makeResolvedTaxRuleVersionFixture(DecimalRate::class);
    $input = new TaxRuleInput(2026, new DateTimeImmutable(), [], new ResolvedParameterSet([]), $ruleVersion);

    expect(fn () => (new RuleInvoker())->invoke($ruleVersion, $input))
        ->toThrow(InvalidRuleImplementationException::class);
});
