<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * The general source was deleted.
 *
 * There is exactly one per taxpayer, created with them, and it is the destination for everything
 * that has nowhere else to go — a typed transaction, a receipt, a rental payment, a bank statement.
 * Removing it would leave a person unable to record income at all, so the next action would have to
 * quietly recreate it, which means it was never really deletable.
 */
final class UndeletableSourceException extends RuntimeException
{
    public static function forGeneral(): self
    {
        return new self('The general source cannot be deleted: it is where everything with no other home is recorded.');
    }
}
