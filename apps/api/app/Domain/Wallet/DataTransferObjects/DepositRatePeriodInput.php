<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

/**
 * One rate period of a deposit's life. `annualRateDecimal` is a plain decimal string
 * (e.g. `"6.50"` for 6.5% per annum) validated by `App\Domain\Wallet\ValueObjects\Decimal` —
 * never a float, since it feeds directly into the interest calculation.
 */
final readonly class DepositRatePeriodInput
{
    public function __construct(
        public string $validFrom,
        public ?string $validTo,
        public string $annualRateDecimal,
    ) {
    }
}
