<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests\Unit\Wallet\DecimalTest;

use Kern\TaxRomania\Wallet\ValueObjects\Decimal;
use Kern\TaxRomania\Wallet\ValueObjects\Exceptions\InvalidDecimalException;

it('parses a valid non-negative decimal string', function (): void {
    expect(Decimal::fromString('12.50')->toString())->toBe('12.50')
        ->and(Decimal::fromInt(7)->toString())->toBe('7')
        ->and(Decimal::zero()->toString())->toBe('0');
});

it('rejects a negative or malformed decimal string', function (string $value): void {
    Decimal::fromString($value);
})->throws(InvalidDecimalException::class)->with(['-1', '1.2.3', 'abc', '']);

it('rejects a negative int', function (): void {
    Decimal::fromInt(-1);
})->throws(InvalidDecimalException::class);

it('adds, multiplies, and divides at a fixed scale using exact BCMath arithmetic', function (): void {
    $a = Decimal::fromString('10');
    $b = Decimal::fromString('3');

    expect($a->add($b, 2)->toString())->toBe('13.00')
        ->and($a->multiply($b, 2)->toString())->toBe('30.00')
        ->and($a->divide($b, 6)->toString())->toBe('3.333333');
});

it('rounds half-up to the requested scale', function (): void {
    expect(Decimal::fromString('1.005')->roundedTo(2)->toString())->toBe('1.01')
        ->and(Decimal::fromString('1.004')->roundedTo(2)->toString())->toBe('1.00')
        ->and(Decimal::fromString('2.5')->roundedTo(0)->toString())->toBe('3');
});

it('converts to the nearest whole integer, rounding half-up', function (): void {
    expect(Decimal::fromString('1019.1780821917808')->toRoundedInt())->toBe(1019)
        ->and(Decimal::fromString('920.5479452054795')->toRoundedInt())->toBe(921);
});
