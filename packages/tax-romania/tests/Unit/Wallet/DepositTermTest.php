<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kern\TaxRomania\Wallet\Services\DepositTerm;

/** A calendar date, at midnight UTC. */
function on(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'UTC');
}

it('clamps a term to the end of a shorter month, as a bank counts it', function (string $start, int $months, string $end): void {
    expect(DepositTerm::end(on($start), $months)->toDateString())->toBe($end);
})->with([
    'into February' => ['2026-01-31', 1, '2026-02-28'],
    'into a leap February' => ['2028-01-31', 1, '2028-02-29'],
    'a plain month' => ['2026-03-15', 6, '2026-09-15'],
    'across a year' => ['2026-08-31', 6, '2027-02-28'],
]);

it('moves a maturity off a weekend to the Monday after, and leaves a weekday alone', function (string $start, int $months, string $maturity): void {
    expect(DepositTerm::maturity(on($start), $months)->toDateString())->toBe($maturity);
})->with([
    'ending on a Saturday' => ['2026-03-31', 7, '2026-11-02'],
    'ending on a Sunday' => ['2026-05-01', 6, '2026-11-02'],
    'ending on a Friday' => ['2026-04-30', 6, '2026-10-30'],
]);

it('reads back the term a stored maturity came from, a weekend move included', function (): void {
    // Six months from 1 May 2026 end on Sunday 1 November and mature on Monday 2 November — read back as
    // six, not the seven the calendar counts; one month from 31 January ends on Saturday 28 February.
    expect(DepositTerm::months(on('2026-05-01'), on('2026-11-02')))->toBe(6)
        ->and(DepositTerm::months(on('2026-01-31'), on('2026-03-02')))->toBe(1)
        // A maturity no term produces: the calendar count.
        ->and(DepositTerm::months(on('2026-01-10'), on('2026-04-20')))->toBe(3);
});

it('counts the days interest runs over, whole', function (): void {
    expect(DepositTerm::days(on('2026-01-01'), on('2026-07-01')))->toBe(181)
        ->and(DepositTerm::days(on('2028-01-01'), on('2029-01-01')))->toBe(366);
});
