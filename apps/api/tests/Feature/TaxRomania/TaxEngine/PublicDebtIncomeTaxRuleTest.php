<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\TaxEngine\PublicDebtIncomeTaxRuleTest;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Enums\FiscalParameterValueType;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedParameterSet;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedTaxRuleVersion;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxFactSnapshot;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\TaxRuleInput;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Enums\TaxFactStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\TaxRuleCannotComputeException;
use Kern\TaxRomania\TaxEngine\Rules\PublicDebtIncomeTaxRule;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

function input(bool $exempt): TaxRuleInput
{
    return new TaxRuleInput(
        fiscalYear: 2026,
        asOf: new DateTimeImmutable('2026-12-31'),
        facts: [new TaxFactSnapshot(
            id: 'coupon', categoryCode: 'PUBLIC_DEBT_INCOME', subtypeCode: null, recognitionDate: new DateTimeImmutable('2026-10-14'),
            cashDate: null, earnedAt: null, receivedAt: null, paidAt: null, distributedAt: null, contractDate: null, transactionDate: null,
            grossAmountRon: Money::ofMinorUnits(76_000, 'RON'), deductibleAmountRon: null, withheldTaxRon: null, status: TaxFactStatus::CONFIRMED,
        )],
        parameters: new ResolvedParameterSet([
            PublicDebtIncomeTaxRule::EXEMPT => new ResolvedFiscalParameter(
                parameterCode: PublicDebtIncomeTaxRule::EXEMPT, parameterVersionId: 'exempt-version', valueType: FiscalParameterValueType::JSON,
                valueJson: ['value' => $exempt], validFrom: new DateTimeImmutable('2026-01-01'), validUntil: null,
                sourceSectionId: 'section-art-93', status: RuleApprovalStatus::APPROVED,
            ),
        ]),
        ruleVersion: new ResolvedTaxRuleVersion(
            id: 'public-debt-version', ruleCode: 'RO.PF.INCOME_TAX.PUBLIC_DEBT', taxCode: 'INCOME_TAX', semanticVersion: '1.0.0',
            validFrom: new DateTimeImmutable('2026-01-01'), validUntil: null, implementationClass: PublicDebtIncomeTaxRule::class,
            configJson: [], status: RuleApprovalStatus::APPROVED, sourceSectionIds: ['section-art-93'],
        ),
    );
}

it('owes nothing on public debt while the law exempts it, and says it read the income', function (): void {
    $outcome = (new PublicDebtIncomeTaxRule())->evaluate(input(exempt: true));

    expect($outcome->tax->isZero())->toBeTrue()
        ->and($outcome->taxableBase->isZero())->toBeTrue()
        ->and($outcome->trace->inputs['income'])->toBe('760.00 RON')
        ->and($outcome->explanationKey)->toBe('tax.ro.pf.income_tax.public_debt');
});

it('refuses to compute once the law is answered as no longer exempting it', function (): void {
    expect(fn () => (new PublicDebtIncomeTaxRule())->evaluate(input(exempt: false)))->toThrow(TaxRuleCannotComputeException::class, 'PUBLIC_DEBT_EXEMPT');
});
