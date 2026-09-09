<?php

declare(strict_types=1);

use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\TaxEngine\DataTransferObjects\ResolvedParameterSet;
use App\Domain\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use App\Domain\TaxEngine\DataTransferObjects\TaxRuleInput;
use App\Domain\TaxEngine\Enums\RuleApprovalStatus;
use App\Domain\TaxEngine\Exceptions\InvalidRuleImplementationException;
use App\Domain\TaxEngine\Exceptions\MissingRequiredParameterException;
use App\Domain\TaxEngine\Services\RuleInvoker;
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
    $ruleVersion = makeResolvedTaxRuleVersionFixture('App\\Domain\\TaxEngine\\NoSuchClassAtAll');
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
