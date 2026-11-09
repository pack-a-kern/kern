<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

use Kern\TaxRomania\Wallet\Enums\HeldAt;

/**
 * A purchase as a person enters it. The instrument's terms — currency, face value, rate, payouts — are not
 * here: they are the source's, read from the instrument, never typed.
 */
final readonly class AddHoldingInput
{
    /**
     * @param  numeric-string  $units
     * @param  numeric-string  $pricePerUnit
     */
    public function __construct(
        public string $taxSubjectId,
        public string $instrumentId,
        public string $units,
        public string $pricePerUnit,
        public string $boughtOn,
        public int $feesMinor,
        public HeldAt $heldAt,
        public ?string $externalRef,
        public ?string $actorId,
    ) {
    }
}
