<?php

declare(strict_types=1);

namespace Tests\Unit\TaxRomania\Wallet\DepositInterestCalculatorTest;

use DateTimeImmutable;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositRatePeriodInput;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Enums\InterestMethod;
use Kern\TaxRomania\Wallet\Enums\PaymentFrequency;
use Kern\TaxRomania\Wallet\Services\DayCount\DayCountCalculator;
use Kern\TaxRomania\Wallet\Services\Interest\DepositInterestCalculator;
use Kern\TaxRomania\Wallet\Services\Interest\DepositPaymentScheduleBuilder;
use Kern\TaxRomania\Wallet\Services\Interest\DepositRateCoverageValidator;

function calculator(): DepositInterestCalculator
{
    return new DepositInterestCalculator(
        new DayCountCalculator(),
        new DepositPaymentScheduleBuilder(),
        new DepositRateCoverageValidator(),
    );
}

/**
 * A single full-year period needs no rounding at all: 100 000 minor units at 10% p.a. over
 * exactly 365/365 of a (non-leap) year is exactly 10 000 minor units.
 */
it('computes a single AT_MATURITY period spanning exactly one year', function (): void {
    $periods = calculator()->calculate(
        principalMinor: 100_000,
        ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '10')],
        method: InterestMethod::SIMPLE,
        frequency: PaymentFrequency::AT_MATURITY,
        dayCountConvention: DayCountConvention::ACTUAL_365,
        openedAt: new DateTimeImmutable('2026-01-01'),
        maturityAt: new DateTimeImmutable('2027-01-01'),
    );

    expect($periods)->toHaveCount(1)
        ->and($periods[0]->grossInterestMinor)->toBe(10_000);
});

/**
 * Hand-computed (exact rational arithmetic, see the handoff note): 100 000 minor units, 12% p.a.,
 * ACTUAL_365, three MONTHLY periods (Jan/Feb/Mar of a non-leap year: 31, 28, 31 days).
 *
 *  - period 1 (31 days): 100000 * 0.12 * 31/365 = 1019.1780821917808...  -> rounds to 1019
 *  - period 2 (28 days): 100000 * 0.12 * 28/365 =  920.5479452054795...  -> rounds to 921
 *  - period 3 (31 days): identical to period 1                          -> rounds to 1019
 *
 * SIMPLE interest always accrues on the untouched original principal, so all three periods use
 * the same base regardless of the previous period's result.
 */
it('computes SIMPLE interest on the untouched principal across monthly periods', function (): void {
    $periods = calculator()->calculate(
        principalMinor: 100_000,
        ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '12')],
        method: InterestMethod::SIMPLE,
        frequency: PaymentFrequency::MONTHLY,
        dayCountConvention: DayCountConvention::ACTUAL_365,
        openedAt: new DateTimeImmutable('2026-01-01'),
        maturityAt: new DateTimeImmutable('2026-04-01'),
    );

    expect(array_map(static fn ($p) => $p->grossInterestMinor, $periods))->toBe([1019, 921, 1019]);
});

/**
 * Same deposit terms as above, but `InterestMethod::COMPOUND`: each period's *rounded* gross
 * interest is capitalised into the base for the next period.
 *
 *  - period 1: identical to the SIMPLE case (nothing to capitalise yet)        -> 1019
 *  - period 2: base = 100000 + 1019 = 101019; 101019*0.12*28/365 = 929.928...  -> rounds to 930
 *  - period 3: base = 101019 + 930 = 101949; 101949*0.12*31/365 = 1039.0418... -> rounds to 1039
 */
it('capitalises the previous period\'s rounded gross interest under COMPOUND', function (): void {
    $periods = calculator()->calculate(
        principalMinor: 100_000,
        ratePeriods: [new DepositRatePeriodInput('2026-01-01', null, '12')],
        method: InterestMethod::COMPOUND,
        frequency: PaymentFrequency::MONTHLY,
        dayCountConvention: DayCountConvention::ACTUAL_365,
        openedAt: new DateTimeImmutable('2026-01-01'),
        maturityAt: new DateTimeImmutable('2026-04-01'),
    );

    expect(array_map(static fn ($p) => $p->grossInterestMinor, $periods))->toBe([1019, 930, 1039]);
});

/**
 * A VARIABLE-rate deposit whose rate changes *inside* a single AT_MATURITY payment period must
 * still be split at the rate boundary. 100 000 minor units, ACTUAL_365, opened 2026-01-01,
 * maturing 2026-03-01 (a non-leap Jan+Feb: 31 + 28 = 59 days); rate is 10% for the first 31 days,
 * then 20% for the remaining 28 days.
 *
 *  - sub-interval A (31 days @ 10%): 100000 * 0.10 * 31/365 =  849.31506849315...
 *  - sub-interval B (28 days @ 20%): 100000 * 0.20 * 28/365 = 1534.24657534247...
 *  - sum = 2383.56164383562... -> rounds to 2384 (only once, at the end of the payment period,
 *    not per sub-interval)
 */
it('splits a payment period at a rate change and rounds once at the end', function (): void {
    $periods = calculator()->calculate(
        principalMinor: 100_000,
        ratePeriods: [
            new DepositRatePeriodInput('2026-01-01', '2026-02-01', '10'),
            new DepositRatePeriodInput('2026-02-01', null, '20'),
        ],
        method: InterestMethod::SIMPLE,
        frequency: PaymentFrequency::AT_MATURITY,
        dayCountConvention: DayCountConvention::ACTUAL_365,
        openedAt: new DateTimeImmutable('2026-01-01'),
        maturityAt: new DateTimeImmutable('2026-03-01'),
    );

    expect($periods)->toHaveCount(1)
        ->and($periods[0]->grossInterestMinor)->toBe(2384);
});
