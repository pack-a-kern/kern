<?php

declare(strict_types=1);

namespace App\Domain\Rental\DataTransferObjects;

use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Enums\TenantType;

final readonly class CreateRentalContractInput
{
    public function __construct(
        public string $taxSubjectId,
        public string $propertyId,
        public TenantType $tenantType,
        public string $tenantIdentity,
        public string $startDate,
        public string $currencyCode,
        public int $rentMinor,
        public RentalPaymentFrequency $paymentFrequency,
        public ?string $endDate = null,
        public ?int $paymentDay = null,
        public ?string $sourceDocumentId = null,
        public ?string $extractionId = null,
    ) {
    }
}
