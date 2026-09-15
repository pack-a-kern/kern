<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * An edit to a deposit that has already matured and been settled.
 *
 * Its interest has been paid and its principal returned; every figure it produced is on the ledger
 * and may already be in a tax return. Changing its terms now would make those figures disagree with
 * the deposit that supposedly produced them.
 */
final class DepositClosedException extends InvalidArgumentException
{
    public static function forAccount(string $accountId): self
    {
        return new self("Deposit {$accountId} has matured and been closed; its terms can no longer change.");
    }
}
