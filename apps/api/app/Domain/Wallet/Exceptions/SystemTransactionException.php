<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * A change to a transaction this application produced from something else's terms.
 *
 * A deposit's funding, its interest and its settlement are consequences of the deposit. Editing one
 * directly would leave the deposit saying one thing and its ledger another; the change belongs on the
 * deposit, which rewrites them.
 */
final class SystemTransactionException extends InvalidArgumentException
{
    public static function forTransaction(string $transactionId): self
    {
        return new self("Transaction {$transactionId} was produced from a deposit's terms; change the deposit instead.");
    }
}
