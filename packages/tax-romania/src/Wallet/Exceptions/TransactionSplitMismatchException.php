<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * `BLUEPRINT.md` §6.3's `transaction_splits` exist to break one transaction's amount into
 * categorised parts — this codebase never persists a set of splits whose amounts do not sum to
 * exactly the parent transaction's `amount_minor`, since a silently-inconsistent split would make
 * every category-based report wrong without an error anywhere.
 */
final class TransactionSplitMismatchException extends InvalidArgumentException
{
    public static function forTotals(int $transactionAmountMinor, int $splitTotalMinor): self
    {
        return new self(
            "Transaction split amounts must sum to the transaction's own amount: "
            . "expected {$transactionAmountMinor}, got {$splitTotalMinor}."
        );
    }
}
