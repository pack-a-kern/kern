<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * A transaction was recorded or edited with an amount of zero.
 *
 * Zero is never a real movement of money. A row asserting that nothing happened is one the rental
 * payment matcher has to consider and can never match, one that appears in an income/outcome total
 * contributing nothing, and one nobody can explain the presence of a year later.
 *
 * The rule lived only in the request validator, which left the action itself able to write a row no
 * screen could have produced. It is here so that every caller — a controller, a future importer, a
 * document parser — hits the same wall.
 */
final class ZeroAmountTransactionException extends InvalidArgumentException
{
    public static function forTransaction(): self
    {
        return new self('A transaction amount must be non-zero; zero is not a movement of money.');
    }
}
