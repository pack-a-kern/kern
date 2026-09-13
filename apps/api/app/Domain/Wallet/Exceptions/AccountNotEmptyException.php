<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A stream source was deleted while transactions still explain a figure through it.
 *
 * Cascading instead would silently remove records a tax figure was computed from, which is the one
 * thing this product must never do quietly. The remedy is in the message: remove or move the
 * transactions first, deliberately.
 *
 * This applies only to sources transactions were *recorded into* — a broker, a crypto exchange,
 * anything typed by hand. A holding is different: a cash source's single row and a deposit's
 * interest schedule are produced from the declaration being deleted, not entered against it, so
 * they go with it rather than blocking it.
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
                'This source cannot be deleted: %d transaction(s) and %d interest event(s) still reference it. Remove or move them first.',
                $transactions,
                $interestEvents,
            ),
            $transactions,
            $interestEvents,
        );
    }
}
