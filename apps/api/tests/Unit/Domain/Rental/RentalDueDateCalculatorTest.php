<?php

declare(strict_types=1);

use App\Domain\Rental\Services\RentalDueDateCalculator;

beforeEach(function (): void {
    $this->calculator = new RentalDueDateCalculator();
});

it('defaults to the period start when no payment day is set', function (): void {
    $due = $this->calculator->forPeriod(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'), null);

    expect($due->format('Y-m-d'))->toBe('2026-01-01');
});

it('uses the payment day within the period start month', function (): void {
    $due = $this->calculator->forPeriod(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'), 10);

    expect($due->format('Y-m-d'))->toBe('2026-01-10');
});

it('clamps a payment day past the end of a short month to the last day of that month', function (): void {
    $due = $this->calculator->forPeriod(new DateTimeImmutable('2026-02-01'), new DateTimeImmutable('2026-03-01'), 31);

    // 2026 is not a leap year: February has 28 days.
    expect($due->format('Y-m-d'))->toBe('2026-02-28');
});

it('clamps a payment day before the period start to the period start itself', function (): void {
    $due = $this->calculator->forPeriod(new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-02-01'), 5);

    expect($due->format('Y-m-d'))->toBe('2026-01-15');
});

it('clamps a payment day at or past the period end to the last day of the period', function (): void {
    $due = $this->calculator->forPeriod(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-10'), 25);

    expect($due->format('Y-m-d'))->toBe('2026-01-09');
});
