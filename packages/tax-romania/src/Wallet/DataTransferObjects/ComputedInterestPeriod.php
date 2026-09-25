<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

use DateTimeImmutable;

/**
 * One payment/capitalisation period's output from
 * `Kern\TaxRomania\Wallet\Services\Interest\DepositInterestCalculator` — pure gross interest, in the
 * deposit's own currency's minor units. No fiscal knowledge (withholding, net) is attached here;
 * see that calculator's docblock.
 */
final readonly class ComputedInterestPeriod
{
    public function __construct(
        public DateTimeImmutable $accrualFrom,
        public DateTimeImmutable $accrualTo,
        public int $grossInterestMinor,
    ) {
    }
}
