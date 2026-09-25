<?php

declare(strict_types=1);

namespace Tests\Unit\TaxRomania\Wallet\DepositRateCoverageValidatorTest;

use DateTimeImmutable;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositRatePeriodInput;
use Kern\TaxRomania\Wallet\Exceptions\InvalidDepositRateCoverageException;
use Kern\TaxRomania\Wallet\Services\Interest\DepositRateCoverageValidator;

it('accepts a single open-ended period covering the whole deposit life', function (): void {
    $validator = new DepositRateCoverageValidator();

    $normalized = $validator->validateAndNormalize(
        [new DepositRatePeriodInput('2026-01-01', null, '6.50')],
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
    );

    expect($normalized)->toHaveCount(1)
        ->and($normalized[0][1]->format('Y-m-d'))->toBe('2027-01-01');
});

it('accepts two consecutive periods with no gap or overlap', function (): void {
    $validator = new DepositRateCoverageValidator();

    $normalized = $validator->validateAndNormalize(
        [
            new DepositRatePeriodInput('2026-02-01', null, '7.00'),
            new DepositRatePeriodInput('2026-01-01', '2026-02-01', '6.50'),
        ],
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
    );

    expect($normalized)->toHaveCount(2)
        ->and($normalized[0][0]->format('Y-m-d'))->toBe('2026-01-01')
        ->and($normalized[1][0]->format('Y-m-d'))->toBe('2026-02-01');
});

it('rejects a gap between rate periods', function (): void {
    $validator = new DepositRateCoverageValidator();

    $validator->validateAndNormalize(
        [
            new DepositRatePeriodInput('2026-01-01', '2026-02-01', '6.50'),
            new DepositRatePeriodInput('2026-02-05', null, '7.00'),
        ],
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
    );
})->throws(InvalidDepositRateCoverageException::class);

it('rejects an overlap between rate periods', function (): void {
    $validator = new DepositRateCoverageValidator();

    $validator->validateAndNormalize(
        [
            new DepositRatePeriodInput('2026-01-01', '2026-02-05', '6.50'),
            new DepositRatePeriodInput('2026-02-01', null, '7.00'),
        ],
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
    );
})->throws(InvalidDepositRateCoverageException::class);

it('rejects rate periods that stop short of maturity', function (): void {
    $validator = new DepositRateCoverageValidator();

    $validator->validateAndNormalize(
        [new DepositRatePeriodInput('2026-01-01', '2026-06-01', '6.50')],
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
    );
})->throws(InvalidDepositRateCoverageException::class);

it('rejects an empty rate period list', function (): void {
    $validator = new DepositRateCoverageValidator();

    $validator->validateAndNormalize(
        [],
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2027-01-01'),
    );
})->throws(InvalidDepositRateCoverageException::class);
