<?php

declare(strict_types=1);

use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\TaxEngine\Enums\FiscalParameterValueType;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidParameterValueShapeException;

/**
 * @param array<string, mixed> $valueJson
 */
function makeResolvedFiscalParameterFixture(FiscalParameterValueType $type, array $valueJson): ResolvedFiscalParameter
{
    return new ResolvedFiscalParameter(
        parameterCode: 'RO.TEST.PARAM',
        parameterVersionId: 'version-1',
        valueType: $type,
        valueJson: $valueJson,
        validFrom: new DateTimeImmutable('2026-01-01'),
        validUntil: null,
        sourceSectionId: 'section-1',
        status: RuleApprovalStatus::CANDIDATE,
    );
}

it('reads a MONEY value as Money', function (): void {
    $parameter = makeResolvedFiscalParameterFixture(FiscalParameterValueType::MONEY, ['amount_minor' => 250000]);

    $money = $parameter->asMoney('RON');

    expect($money->minorUnits)->toBe(250000)
        ->and($money->currencyCode)->toBe('RON');
});

it('reads a DECIMAL value as a DecimalRate', function (): void {
    $parameter = makeResolvedFiscalParameterFixture(FiscalParameterValueType::DECIMAL, ['value' => '0.10']);

    expect($parameter->asDecimalRate()->toString())->toBe('0.10');
});

it('reads an INTEGER value', function (): void {
    $parameter = makeResolvedFiscalParameterFixture(FiscalParameterValueType::INTEGER, ['value' => 42]);

    expect($parameter->asInteger())->toBe(42);
});

it('reads a DATE value', function (): void {
    $parameter = makeResolvedFiscalParameterFixture(FiscalParameterValueType::DATE, ['value' => '2026-06-01']);

    expect($parameter->asDate()->format('Y-m-d'))->toBe('2026-06-01');
});

it('reads a JSON value verbatim', function (): void {
    $parameter = makeResolvedFiscalParameterFixture(FiscalParameterValueType::JSON, ['anything' => ['nested' => true]]);

    expect($parameter->asJson())->toBe(['anything' => ['nested' => true]]);
});

it('refuses to read a value as the wrong type', function (): void {
    $parameter = makeResolvedFiscalParameterFixture(FiscalParameterValueType::MONEY, ['amount_minor' => 100]);

    expect(fn () => $parameter->asDecimalRate())->toThrow(InvalidParameterValueShapeException::class);
});

it('refuses a malformed MONEY shape', function (): void {
    $parameter = makeResolvedFiscalParameterFixture(FiscalParameterValueType::MONEY, ['amount_minor' => 'not-an-int']);

    expect(fn () => $parameter->asMoney('RON'))->toThrow(InvalidParameterValueShapeException::class);
});
