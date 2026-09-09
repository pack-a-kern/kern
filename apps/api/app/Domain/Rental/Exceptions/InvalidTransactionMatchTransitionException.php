<?php

declare(strict_types=1);

namespace App\Domain\Rental\Exceptions;

use App\Domain\Rental\Enums\TransactionMatchStatus;
use RuntimeException;

/**
 * A confirm/reject decision is only meaningful against a `PROPOSED` match — confirming an already
 * `CONFIRMED` link, or rejecting one nothing ever proposed, would silently hide a logic error
 * upstream rather than surface it.
 */
final class InvalidTransactionMatchTransitionException extends RuntimeException
{
    public static function forStatus(TransactionMatchStatus $status): self
    {
        return new self("Cannot decide a transaction match from status {$status->value}; it must be PROPOSED.");
    }
}
