<?php

declare(strict_types=1);

use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Exceptions\InvalidDateRangeException;
use App\Domain\Rental\Services\AccrualPeriodBuilder;

beforeEach(function (): void {
    $this->builder = new AccrualPeriodBuilder();
});

it('splits a version life into exact monthly periods', function (): void {
    $periods = $this->builder->build(
        RentalPaymentFrequency::MONTHLY,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-04-01'),
    );

    expect($periods)->toHaveCount(3)
        ->and($periods[0][0]->format('Y-m-d'))->toBe('2026-01-01')
        ->and($periods[0][1]->format('Y-m-d'))->toBe('2026-02-01')
        ->and($periods[1][0]->format('Y-m-d'))->toBe('2026-02-01')
        ->and($periods[1][1]->format('Y-m-d'))->toBe('2026-03-01')
        ->and($periods[2][0]->format('Y-m-d'))->toBe('2026-03-01')
        ->and($periods[2][1]->format('Y-m-d'))->toBe('2026-04-01');
});

it('clips the final period when the life is not an exact multiple of the frequency', function (): void {
    $periods = $this->builder->build(
        RentalPaymentFrequency::MONTHLY,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-01-15'),
    );

    expect($periods)->toHaveCount(1)
        ->and($periods[0][0]->format('Y-m-d'))->toBe('2026-01-01')
        ->and($periods[0][1]->format('Y-m-d'))->toBe('2026-01-15');
});

it('splits quarterly, semi-annual, and annual frequencies correctly', function (): void {
    $quarterly = $this->builder->build(RentalPaymentFrequency::QUARTERLY, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-07-01'));
    expect($quarterly)->toHaveCount(2)
        ->and($quarterly[0][1]->format('Y-m-d'))->toBe('2026-04-01');

    $semiAnnual = $this->builder->build(RentalPaymentFrequency::SEMI_ANNUAL, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01'));
    expect($semiAnnual)->toHaveCount(2)
        ->and($semiAnnual[0][1]->format('Y-m-d'))->toBe('2026-07-01');

    $annual = $this->builder->build(RentalPaymentFrequency::ANNUAL, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2028-01-01'));
    expect($annual)->toHaveCount(2)
        ->and($annual[0][1]->format('Y-m-d'))->toBe('2027-01-01');
});

it('rejects a validTo that does not come strictly after validFrom', function (): void {
    expect(fn () => $this->builder->build(
        RentalPaymentFrequency::MONTHLY,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-01-01'),
    ))->toThrow(InvalidDateRangeException::class);
});
