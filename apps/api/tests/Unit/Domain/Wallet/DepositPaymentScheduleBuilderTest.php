<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\DepositPaymentScheduleBuilderTest;

use DateTimeImmutable;
use Kern\TaxRomania\Wallet\Enums\PaymentFrequency;
use Kern\TaxRomania\Wallet\Exceptions\InvalidDateRangeException;
use Kern\TaxRomania\Wallet\Services\Interest\DepositPaymentScheduleBuilder;

it('produces a single period spanning the whole deposit life for AT_MATURITY', function (): void {
    $builder = new DepositPaymentScheduleBuilder();

    $periods = $builder->build(
        PaymentFrequency::AT_MATURITY,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
    );

    expect($periods)->toHaveCount(1)
        ->and($periods[0][0]->format('Y-m-d'))->toBe('2026-01-01')
        ->and($periods[0][1]->format('Y-m-d'))->toBe('2027-01-01');
});

it('produces one boundary per month for an exact multiple of the term', function (): void {
    $builder = new DepositPaymentScheduleBuilder();

    $periods = $builder->build(
        PaymentFrequency::MONTHLY,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-04-01'),
    );

    expect($periods)->toHaveCount(3);
    expect(array_map(static fn (array $p): array => [$p[0]->format('Y-m-d'), $p[1]->format('Y-m-d')], $periods))
        ->toBe([
            ['2026-01-01', '2026-02-01'],
            ['2026-02-01', '2026-03-01'],
            ['2026-03-01', '2026-04-01'],
        ]);
});

it('clips the final period to maturity when the term is not an exact multiple of the frequency', function (): void {
    $builder = new DepositPaymentScheduleBuilder();

    $periods = $builder->build(
        PaymentFrequency::MONTHLY,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-02-15'),
    );

    expect(array_map(static fn (array $p): array => [$p[0]->format('Y-m-d'), $p[1]->format('Y-m-d')], $periods))
        ->toBe([
            ['2026-01-01', '2026-02-01'],
            ['2026-02-01', '2026-02-15'],
        ]);
});

it('refuses a deposit life that does not end after it starts', function (): void {
    $builder = new DepositPaymentScheduleBuilder();

    $builder->build(
        PaymentFrequency::MONTHLY,
        new DateTimeImmutable('2026-04-01'),
        new DateTimeImmutable('2026-01-01'),
    );
})->throws(InvalidDateRangeException::class);
