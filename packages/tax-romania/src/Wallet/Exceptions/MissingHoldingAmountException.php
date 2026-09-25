<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;
use Kern\TaxRomania\Wallet\Enums\AccountKind;

/**
 * A cash holding was opened without the value that makes it one, or a value was supplied for a kind
 * that has no use for it.
 *
 * The mirror of {@see MissingDepositTermsException}, for the same reason: a holding's entries are
 * produced from its own declaration rather than typed into it, so the declaration has to arrive with
 * it. A cash source with no amount can never report anything — nothing else is allowed to add one.
 */
final class MissingHoldingAmountException extends InvalidArgumentException
{
    public static function forKind(AccountKind $kind): self
    {
        return new self("A {$kind->value} source requires the amount it holds.");
    }

    public static function unexpectedForKind(AccountKind $kind): self
    {
        return new self("A {$kind->value} source receives transactions and cannot declare a holding amount.");
    }
}
