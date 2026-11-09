<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests\Unit\Wallet\DayCountCalculatorTest;

use DateTimeImmutable;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Exceptions\InvalidDateRangeException;
use Kern\TaxRomania\Wallet\Services\DayCount\DayCountCalculator;
use Kern\TaxRomania\Wallet\ValueObjects\Decimal;

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

it('computes ACTUAL_ACTUAL as a Tezaur prospectus does: days accrued over the days of the current year', function (string $from, string $to, string $expected): void {
    $fraction = (new DayCountCalculator())->yearFraction(DayCountConvention::ACTUAL_ACTUAL, new DateTimeImmutable($from), new DateTimeImmutable($to));

    expect($fraction->roundedTo(6)->toString())->toBe($expected);
})->with([
    // Art. 13 (2): D = [face value × rate × days accrued] / days in the current year, the current year being
    // the interest period. A whole period is a whole year's interest, whether it holds 29 February or not.
    'a whole period of 365 days' => ['2026-11-10', '2027-11-10', '1.000000'],
    'a whole period holding 29 February' => ['2027-11-10', '2028-11-10', '1.000000'],
    // 111 days of a 365-day year; 112 days of a 366-day one.
    'part of a common year' => ['2026-11-10', '2027-03-01', '0.304110'],
    'part of a leap year' => ['2027-11-10', '2028-03-01', '0.306011'],
    // The year from 29 February ends on 28 February: 365 days, not 366.
    'a year from 29 February' => ['2028-02-29', '2029-02-28', '1.000000'],
]);

it('gives the interest of art. 13 for one leu at 7 % over a whole period, on 10 000 units', function (): void {
    $fraction = (new DayCountCalculator())->yearFraction(DayCountConvention::ACTUAL_ACTUAL, new DateTimeImmutable('2027-11-10'), new DateTimeImmutable('2028-11-10'));
    $perUnit = Decimal::fromString('1')->multiply(Decimal::fromString('0.07'), 15)->multiply($fraction, 15);

    // D × the units held, rounded to the ban (alin. (4) and (5)): 700,00 lei.
    expect($perUnit->multiply(Decimal::fromInt(10_000), 15)->roundedTo(2)->toString())->toBe('700.00');
});
