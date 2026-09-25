<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests\Unit\Wallet\DayCountCalculatorTest;

use DateTimeImmutable;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Exceptions\InvalidDateRangeException;
use Kern\TaxRomania\Wallet\Services\DayCount\DayCountCalculator;

/**
 * Hand-computed expectations — see `DayCountCalculator`'s own docblock for the worked examples
 * this test pins down.
 */
it('computes ACTUAL_360: 90 actual days (Jan+Feb+Mar in a non-leap year) over 360', function (): void {
    $calculator = new DayCountCalculator();

    $fraction = $calculator->yearFraction(
        DayCountConvention::ACTUAL_360,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-04-01'),
    );

    expect($fraction->roundedTo(6)->toString())->toBe('0.250000');
});

it('computes ACTUAL_365: the same 90 actual days over a fixed 365', function (): void {
    $calculator = new DayCountCalculator();

    $fraction = $calculator->yearFraction(
        DayCountConvention::ACTUAL_365,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-04-01'),
    );

    // 90 / 365 = 0.24657534246575... -> rounds to 0.246575 at 6 dp.
    expect($fraction->roundedTo(6)->toString())->toBe('0.246575');
});

it('computes THIRTY_360 for a plain one-month period', function (): void {
    $calculator = new DayCountCalculator();

    $fraction = $calculator->yearFraction(
        DayCountConvention::THIRTY_360,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-02-01'),
    );

    // (0*360 + 1*30 + 0) / 360 = 30/360 = 1/12 = 0.08333... -> rounds to 0.083333.
    expect($fraction->roundedTo(6)->toString())->toBe('0.083333');
});

it('applies the 31-day end-of-month adjustment rule for THIRTY_360', function (): void {
    $calculator = new DayCountCalculator();

    $fraction = $calculator->yearFraction(
        DayCountConvention::THIRTY_360,
        new DateTimeImmutable('2026-01-31'),
        new DateTimeImmutable('2026-03-31'),
    );

    // D1=31 -> 30; D2=31 and D1(adjusted)=30 -> D2 -> 30.
    // days = (0*360) + (2*30) + (30-30) = 60; 60/360 = 1/6 = 0.16666... -> rounds to 0.166667.
    expect($fraction->roundedTo(6)->toString())->toBe('0.166667');
});

it('refuses a period that does not end strictly after it starts', function (): void {
    $calculator = new DayCountCalculator();

    $calculator->yearFraction(
        DayCountConvention::ACTUAL_365,
        new DateTimeImmutable('2026-04-01'),
        new DateTimeImmutable('2026-01-01'),
    );
})->throws(InvalidDateRangeException::class);
