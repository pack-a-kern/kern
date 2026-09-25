<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;
use Kern\TaxRomania\Wallet\Enums\AccountKind;

/**
 * A transaction was recorded against a source whose entries are produced, not entered.
 *
 * A cash holding is one declared value and a term deposit's movements are computed from its terms,
 * so an arbitrary transaction posted into either detaches the ledger from the thing it describes —
 * a deposit that says it earns 4% and also contains a hand-typed 5.000 RON credit is not reporting
 * anything. The form stops offering these kinds; this is the same rule where it cannot be bypassed,
 * for an importer or a parser that never saw the form.
 */
final class HoldingSourceException extends InvalidArgumentException
{
    public static function forKind(AccountKind $kind): self
    {
        return new self(
            "Transactions cannot be recorded against a {$kind->value} source: its entries come from its own terms.",
        );
    }
}
