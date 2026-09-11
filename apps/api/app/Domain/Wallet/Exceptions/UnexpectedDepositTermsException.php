<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use App\Domain\Wallet\Enums\AccountKind;
use InvalidArgumentException;

/**
 * Deposit terms were supplied for an account that is not a term deposit.
 *
 * The other half of the invariant: a maturity date and a day-count convention on a current account
 * describe a deposit that does not exist, and would quietly produce an interest schedule for money
 * that was never locked up.
 */
final class UnexpectedDepositTermsException extends InvalidArgumentException
{
    public static function forKind(AccountKind $kind): self
    {
        return new self("An account of kind {$kind->value} must not carry deposit terms.");
    }
}
