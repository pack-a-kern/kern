<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

/**
 * A sale before maturity as a person enters it: what their broker or bank gave them. The amount received
 * and the result are computed from it, never typed.
 */
final readonly class SellHoldingInput
{
    /**
     * @param  numeric-string  $units
     * @param  numeric-string  $pricePerUnit
     */
    public function __construct(
        public string $accountId,
        public string $instrumentId,
        public string $units,
        public string $pricePerUnit,
        public string $soldOn,
        public int $feesMinor,
        public ?string $actorId,
    ) {
    }
}
