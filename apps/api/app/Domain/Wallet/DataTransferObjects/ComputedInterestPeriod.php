<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use DateTimeImmutable;

/**
 * One payment/capitalisation period's output from
 * `App\Domain\Wallet\Services\Interest\DepositInterestCalculator` — pure gross interest, in the
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
