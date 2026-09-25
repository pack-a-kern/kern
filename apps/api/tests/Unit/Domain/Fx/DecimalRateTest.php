<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Fx\DecimalRateTest;

use InvalidArgumentException;
use Kern\TaxRomania\Fx\Exceptions\InvalidDecimalRateException;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;

it('parses a valid non-negative decimal string', function (): void {
    expect(DecimalRate::fromString('4.976400')->toString())->toBe('4.976400')
        ->and(DecimalRate::fromString('0')->toString())->toBe('0')
        ->and((string) DecimalRate::fromString('12')->toString())->toBe('12');
});

it('rejects a negative value', function (): void {
    DecimalRate::fromString('-1.5');
})->throws(InvalidDecimalRateException::class);

it('rejects a non-numeric value', function (): void {
    DecimalRate::fromString('abc');
})->throws(InvalidDecimalRateException::class);

it('rejects scientific notation and other non-plain-decimal forms', function (): void {
    DecimalRate::fromString('1e10');
})->throws(InvalidDecimalRateException::class);

it('adds two rates at a fixed scale', function (): void {
    $sum = DecimalRate::fromString('4.9764')->add(DecimalRate::fromString('4.5821'), 4);

    expect($sum->toString())->toBe('9.5585');
});

it('divides by an integer, truncating (not rounding) at the given scale', function (): void {
    $result = DecimalRate::fromString('10')->divideByInt(3, 4);

    expect($result->toString())->toBe('3.3333');
});

it('rejects division by a non-positive divisor', function (): void {
    DecimalRate::fromString('10')->divideByInt(0, 4);
})->throws(InvalidArgumentException::class);

it('rounds half-up to a target scale', function (): void {
    expect(DecimalRate::fromString('1.23449')->roundedTo(4)->toString())->toBe('1.2345')
        ->and(DecimalRate::fromString('1.23444')->roundedTo(4)->toString())->toBe('1.2344')
        ->and(DecimalRate::fromString('1.99995')->roundedTo(4)->toString())->toBe('2.0000');
});

it('computes an absolute difference regardless of operand order', function (): void {
    $a = DecimalRate::fromString('5.0000');
    $b = DecimalRate::fromString('4.9764');

    expect($a->absoluteDifference($b, 4)->toString())->toBe('0.0236')
        ->and($b->absoluteDifference($a, 4)->toString())->toBe('0.0236');
});

it('compares less-than-or-equal at a given scale', function (): void {
    $tolerance = DecimalRate::fromString('0.0001');

    expect(DecimalRate::fromString('0.0001')->isLessThanOrEqualTo($tolerance, 4))->toBeTrue()
        ->and(DecimalRate::fromString('0.00011')->roundedTo(4)->isLessThanOrEqualTo($tolerance, 4))->toBeTrue()
        ->and(DecimalRate::fromString('0.0002')->isLessThanOrEqualTo($tolerance, 4))->toBeFalse();
});

it('treats values with differing trailing precision as equal when numerically equal', function (): void {
    expect(DecimalRate::fromString('4.9764')->equals(DecimalRate::fromString('4.976400')))->toBeTrue()
        ->and(DecimalRate::fromString('4.9764')->equals(DecimalRate::fromString('4.9765')))->toBeFalse();
});
