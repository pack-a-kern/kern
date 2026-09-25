<?php

declare(strict_types=1);

use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;
use Kern\TaxRomania\TaxEngine\Enums\RoundingMode;
use Kern\TaxRomania\TaxEngine\Exceptions\CurrencyMismatchException;
use Kern\TaxRomania\TaxEngine\Exceptions\InvalidCurrencyCodeException;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * `docs/AGENTS.md` rule 6 / `BLUEPRINT.md` §11.3 — Money is integer minor units plus an explicit
 * currency, arithmetic exclusively via BCMath, never a PHP float. This test asserts it via
 * reflection over every property and method's *type declarations* (never `float`) — deliberately
 * not a raw source-text search, since this class's own docblocks legitimately discuss "never a
 * float" in prose.
 */
it('never declares a float type on any property, parameter, or return type', function (): void {
    $reflection = new ReflectionClass(Money::class);
    $floatTypeNames = [];

    $describeType = static function (?ReflectionType $type) use (&$floatTypeNames, &$describeType): void {
        if ($type instanceof ReflectionNamedType) {
            if (strtolower($type->getName()) === 'float') {
                $floatTypeNames[] = $type->getName();
            }

            return;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $inner) {
                $describeType($inner);
            }
        }
    };

    foreach ($reflection->getProperties() as $property) {
        $describeType($property->getType());
    }

    foreach ($reflection->getMethods() as $method) {
        $describeType($method->getReturnType());

        foreach ($method->getParameters() as $parameter) {
            $describeType($parameter->getType());
        }
    }

    expect($floatTypeNames)->toBe([]);
});

it('adds two amounts in the same currency', function (): void {
    $a = Money::ofMinorUnits(1000, 'RON');
    $b = Money::ofMinorUnits(250, 'RON');

    expect($a->add($b)->minorUnits)->toBe(1250);
});

it('refuses to add amounts in different currencies', function (): void {
    $ron = Money::ofMinorUnits(1000, 'RON');
    $eur = Money::ofMinorUnits(1000, 'EUR');

    expect(fn () => $ron->add($eur))->toThrow(CurrencyMismatchException::class);
});

it('refuses to add amounts with different decimal-place scales even in the same currency code', function (): void {
    $twoPlaces = Money::ofMinorUnits(1000, 'XYZ', 2);
    $threePlaces = Money::ofMinorUnits(1000, 'XYZ', 3);

    expect(fn () => $twoPlaces->add($threePlaces))->toThrow(CurrencyMismatchException::class);
});

it('subtracts and negates correctly', function (): void {
    $a = Money::ofMinorUnits(1000, 'RON');
    $b = Money::ofMinorUnits(400, 'RON');

    expect($a->subtract($b)->minorUnits)->toBe(600)
        ->and($a->negated()->minorUnits)->toBe(-1000);
});

it('compares amounts', function (): void {
    $small = Money::ofMinorUnits(100, 'RON');
    $large = Money::ofMinorUnits(200, 'RON');

    expect($small->isLessThan($large))->toBeTrue()
        ->and($large->isGreaterThan($small))->toBeTrue()
        ->and($small->max($large)->minorUnits)->toBe(200)
        ->and($small->min($large)->minorUnits)->toBe(100)
        ->and($small->equals(Money::ofMinorUnits(100, 'RON')))->toBeTrue();
});

it('rejects a malformed currency code', function (): void {
    expect(fn () => Money::ofMinorUnits(100, 'R1'))->toThrow(InvalidCurrencyCodeException::class);
});

it('renders a human-readable string with the decimal point in the right place', function (): void {
    expect(Money::ofMinorUnits(123456, 'RON')->toString())->toBe('1234.56 RON')
        ->and(Money::ofMinorUnits(-500, 'RON')->toString())->toBe('-5.00 RON')
        ->and(Money::ofMinorUnits(5, 'RON')->toString())->toBe('0.05 RON');
});

it('multiplies by a decimal rate and rounds half up', function (): void {
    $base = Money::ofMinorUnits(10005, 'RON'); // 100.05
    $rate = DecimalRate::fromString('0.10'); // 10%

    // exact = 1000.5 minor units -> half up -> 1001
    expect($base->multipliedByDecimal($rate, RoundingMode::HALF_UP)->minorUnits)->toBe(1001);
});

it('supports every rounding mode distinctly on the same exact fraction', function (): void {
    $base = Money::ofMinorUnits(100, 'RON'); // 1.00
    $rate = DecimalRate::fromString('0.125'); // exact = 12.5 minor units

    expect($base->multipliedByDecimal($rate, RoundingMode::DOWN)->minorUnits)->toBe(12)
        ->and($base->multipliedByDecimal($rate, RoundingMode::UP)->minorUnits)->toBe(13)
        ->and($base->multipliedByDecimal($rate, RoundingMode::HALF_UP)->minorUnits)->toBe(13)
        ->and($base->multipliedByDecimal($rate, RoundingMode::HALF_DOWN)->minorUnits)->toBe(12)
        ->and($base->multipliedByDecimal($rate, RoundingMode::HALF_EVEN)->minorUnits)->toBe(12);
});

it('rounds a negative amount symmetrically to its positive counterpart', function (): void {
    $positive = Money::ofMinorUnits(100, 'RON');
    $negative = Money::ofMinorUnits(-100, 'RON');
    $rate = DecimalRate::fromString('0.125');

    $positiveResult = $positive->multipliedByDecimal($rate, RoundingMode::HALF_UP)->minorUnits;
    $negativeResult = $negative->multipliedByDecimal($rate, RoundingMode::HALF_UP)->minorUnits;

    expect($negativeResult)->toBe(-$positiveResult);
});

it('converts to another currency using an explicit per-unit rate', function (): void {
    $eur = Money::ofMinorUnits(10000, 'EUR'); // 100.00 EUR
    $rate = DecimalRate::fromString('5.0000'); // 1 EUR = 5 RON

    $ron = $eur->convertedTo('RON', 2, $rate, RoundingMode::HALF_UP);

    expect($ron->currencyCode)->toBe('RON')
        ->and($ron->minorUnits)->toBe(50000); // 500.00 RON
});

it('converts correctly across different minor-unit decimal-place scales', function (): void {
    // 1 unit of a 0-decimal-place currency = 150 minor units of a 2-decimal-place currency.
    $source = Money::ofMinorUnits(10, 'XYZ', 0); // 10 whole units
    $rate = DecimalRate::fromString('1.50');

    $target = $source->convertedTo('RON', 2, $rate, RoundingMode::HALF_UP);

    expect($target->minorUnits)->toBe(1500); // 15.00 RON
});

it('is zero, positive, and negative correctly', function (): void {
    expect(Money::zero('RON')->isZero())->toBeTrue()
        ->and(Money::ofMinorUnits(1, 'RON')->isPositive())->toBeTrue()
        ->and(Money::ofMinorUnits(-1, 'RON')->isNegative())->toBeTrue();
});
