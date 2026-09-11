<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * An account was deleted while records still depend on it.
 *
 * Deleting is not the same as closing, and this is the line between them. A closed account keeps
 * its history — `closed_at` says money stopped moving through it, and last year's transactions
 * still explain last year's return. Deleting is for an account that should never have existed, and
 * that is only true while nothing points at it.
 *
 * Cascading instead would silently remove transactions a tax figure was computed from, which is the
 * one thing this product must never do quietly. The remedy is in the message: close it, or remove
 * what depends on it first.
 */
final class AccountNotEmptyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $transactionCount,
        public readonly int $interestEventCount,
    ) {
        parent::__construct($message);
    }

    public static function forCounts(int $transactions, int $interestEvents): self
    {
        return new self(
            sprintf(
                'This account cannot be deleted: %d transaction(s) and %d interest event(s) still reference it. Close it instead.',
                $transactions,
                $interestEvents,
            ),
            $transactions,
            $interestEvents,
        );
    }
}
