<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * A deposit's rate periods must cover its whole life, `[opened_at, maturity_at)`, with no gap and
 * no overlap — otherwise `Kern\TaxRomania\Wallet\Services\Interest\DepositInterestCalculator` would
 * either silently skip days (a gap) or double-count them (an overlap). Thrown eagerly, at
 * deposit-creation/schedule-generation time, rather than letting either failure mode reach a
 * stored `deposit_interest_events` row.
 */
final class InvalidDepositRateCoverageException extends InvalidArgumentException
{
    public static function gapOrOverlapBetween(string $expectedFrom, string $actualFrom): self
    {
        return new self(
            "Deposit rate periods must cover the deposit's life with no gap or overlap: expected "
            . "the next period to start on {$expectedFrom}, found one starting {$actualFrom}."
        );
    }

    public static function doesNotReachMaturity(string $coveredUntil, string $maturityAt): self
    {
        return new self(
            "Deposit rate periods only cover up to {$coveredUntil}, but the deposit matures "
            . "{$maturityAt}."
        );
    }

    public static function empty(): self
    {
        return new self('A deposit must have at least one rate period.');
    }
}
