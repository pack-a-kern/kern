<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Exceptions;

use RuntimeException;

/**
 * Nothing in this import can say what currency its figures are in.
 *
 * A statement in one currency usually states it nowhere: not in a column, not on a detail line. The
 * account it is being read into normally answers that, but an account has not carried a currency
 * since it became a source — so when neither the file nor the account knows, nobody does.
 *
 * ## Why this refuses instead of importing
 *
 * A transaction stores a currency and the column is not nullable, so rows without one can never be
 * written. Staging them anyway produced the worst possible screen: 1 700 rows, every one unticked
 * for a reason the screen did not give, and an Import button that reported "0 transactions imported"
 * as though that were a result. Refused here instead, where the form is still open and one select
 * fixes it.
 */
final class CurrencyRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Neither the statement nor the account says which currency these figures are in.');
    }
}
