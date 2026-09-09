<?php

declare(strict_types=1);

namespace App\Domain\Rental\DataTransferObjects;

use App\Domain\Rental\Enums\RentalPaymentFrequency;

final readonly class AmendRentalContractInput
{
    public function __construct(
        public string $rentalContractId,
        public string $taxSubjectId,
        public string $effectiveFrom,
        public int $rentMinor,
        public RentalPaymentFrequency $paymentFrequency,
        public ?int $paymentDay = null,
        public ?string $terms = null,
        public ?string $sourceDocumentId = null,
    ) {
    }
}
