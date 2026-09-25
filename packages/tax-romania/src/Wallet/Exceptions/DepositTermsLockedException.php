<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * An edit that would contradict interest already posted to the ledger.
 *
 * A posted period is a transaction: money that arrived, computed from the principal, start date,
 * method and frequency as they were. Changing any of those afterwards leaves that transaction
 * explained by terms the deposit no longer has. The rate and the maturity only reach periods not
 * yet posted, so those stay editable — within the one limit that the maturity cannot move back past
 * a period already paid.
 */
final class DepositTermsLockedException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $fields
     */
    public static function afterInterestPosted(array $fields): self
    {
        return new self('These deposit terms can no longer change because interest has been posted: ' . implode(', ', $fields) . '.');
    }

    public static function maturityBeforePostedInterest(string $maturityAt, string $lastPostedAt): self
    {
        return new self("Maturity {$maturityAt} is before interest already posted up to {$lastPostedAt}.");
    }
}
