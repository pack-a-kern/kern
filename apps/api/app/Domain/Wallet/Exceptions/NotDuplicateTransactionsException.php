<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * A merge or a keep asked about transactions that are not a set of lookalikes.
 *
 * Checked on the server rather than trusted from the screen: merging deletes rows, and a request
 * naming two unrelated transactions would otherwise delete a real payment.
 */
final class NotDuplicateTransactionsException extends InvalidArgumentException
{
    public static function tooFew(): self
    {
        return new self('At least two transactions are needed to merge or keep as separate.');
    }

    public static function notAllFound(): self
    {
        return new self('One or more of these transactions do not exist.');
    }

    public static function systemTransaction(): self
    {
        return new self('A deposit\'s own transactions cannot be merged or kept; they follow the deposit.');
    }

    public static function differentFigures(): self
    {
        return new self('These transactions do not share a date, an amount and a currency.');
    }
}
