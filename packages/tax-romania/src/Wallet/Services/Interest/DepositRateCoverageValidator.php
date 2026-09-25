<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\Interest;

use DateTimeImmutable;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositRatePeriodInput;
use Kern\TaxRomania\Wallet\Exceptions\InvalidDepositRateCoverageException;
use Kern\TaxRomania\Wallet\ValueObjects\Decimal;

/**
 * Validates that a deposit's rate periods cover its whole life, `[openedAt, maturityAt)`, with no
 * gap and no overlap — used both eagerly, when a deposit is opened
 * (`Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount`, so bad data is rejected before it reaches the
 * database), and again by `Kern\TaxRomania\Wallet\Services\Interest\DepositInterestCalculator` (which
 * cannot assume the database still reflects what it validated at creation time — a `VARIABLE`
 * deposit's rate periods can be edited later). One implementation, not two: the coverage
 * invariant is stable domain knowledge shared by both call sites
 * (`docs/CODE-QUALITY.md` §1), unlike the merely-similar-shaped `Money`/`Decimal` value objects
 * documented separately in `docs/handoff/021-wallet.md`.
 */
final class DepositRateCoverageValidator
{
    /**
     * @param list<DepositRatePeriodInput> $ratePeriods
     *
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: Decimal}> sorted by start,
     *                                                                              covering exactly
     *                                                                              `[openedAt, maturityAt)`
     */
    public function validateAndNormalize(array $ratePeriods, DateTimeImmutable $openedAt, DateTimeImmutable $maturityAt): array
    {
        if ($ratePeriods === []) {
            throw InvalidDepositRateCoverageException::empty();
        }

        $normalized = [];

        foreach ($ratePeriods as $period) {
            $from = new DateTimeImmutable($period->validFrom);
            $to = $period->validTo === null ? $maturityAt : new DateTimeImmutable($period->validTo);

            $normalized[] = [$from, $to, Decimal::fromString($period->annualRateDecimal)];
        }

        usort($normalized, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $cursor = $openedAt;

        // Value-equality comparison via <=> (not === / !==): two distinct DateTimeImmutable
        // instances representing the identical moment are never the same object, so === always
        // reports them different regardless of value. `Illuminate\Support\Carbon` overloads
        // ==/!= to compare by value, but that only applies to Carbon instances; these are plain
        // DateTimeImmutable, and Pint's PSR-12 fixer rewrites a loose == comparison to strict ===
        // on sight, which would silently turn this into an always-true "gap or overlap" check —
        // this happened once already during this module's development. <=> is PHP's built-in
        // by-value DateTime comparison and is not touched by that fixer.
        foreach ($normalized as $period) {
            [$from, $to] = $period;

            if (($from <=> $cursor) !== 0) {
                throw InvalidDepositRateCoverageException::gapOrOverlapBetween(
                    $cursor->format('Y-m-d'),
                    $from->format('Y-m-d'),
                );
            }

            $cursor = $to;
        }

        if (($cursor <=> $maturityAt) !== 0) {
            throw InvalidDepositRateCoverageException::doesNotReachMaturity(
                $cursor->format('Y-m-d'),
                $maturityAt->format('Y-m-d'),
            );
        }

        return $normalized;
    }
}
