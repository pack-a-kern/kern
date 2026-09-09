<?php

declare(strict_types=1);

namespace App\Domain\Rental\DataTransferObjects;

final readonly class RecordRentalPaymentInput
{
    public function __construct(
        public string $taxSubjectId,
        public string $rentalContractId,
        public int $amountMinor,
        public string $currencyCode,
        public string $paidAt,
        public ?string $accrualId = null,
    ) {
    }
}
