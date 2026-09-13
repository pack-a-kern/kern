<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use App\Domain\Wallet\Enums\AccountKind;
use InvalidArgumentException;

/**
 * A `TERM_DEPOSIT` account was opened without the terms that make it one.
 *
 * Half of the invariant the old separate `deposits` table could not express. Without terms there is
 * no principal, no maturity and no rate — nothing to compute interest from — so the account would
 * exist as a deposit that can never report what it earns.
 */
final class MissingDepositTermsException extends InvalidArgumentException
{
    public static function forKind(AccountKind $kind): self
    {
        return new self("An account of kind {$kind->value} requires deposit terms.");
    }
}
