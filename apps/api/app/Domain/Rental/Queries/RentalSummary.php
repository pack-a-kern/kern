<?php

declare(strict_types=1);

namespace App\Domain\Rental\Queries;

/**
 * One rental as the rentals screen shows it: the property, the tenant, the terms in force, and what
 * the contract owes each year.
 */
final readonly class RentalSummary
{
    /**
     * @param  list<array{year: int, share_minor: int}>  $years  newest first
     */
    public function __construct(
        public string $id,
        public string $propertyType,
        public string $address,
        public string $ownershipPercent,
        public string $tenantType,
        public string $tenantName,
        public string $startDate,
        public ?string $endDate,
        public string $status,
        public string $currencyCode,
        /** The rent in force now, or the last one for an ended rental. */
        public int $rentMinor,
        public string $paymentFrequency,
        public ?int $paymentDay,
        public ?string $sourceDocumentId,
        public array $years,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'property_type' => $this->propertyType,
            'address' => $this->address,
            'ownership_percent' => $this->ownershipPercent,
            'tenant_type' => $this->tenantType,
            'tenant_name' => $this->tenantName,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'status' => $this->status,
            'currency_code' => $this->currencyCode,
            'rent_minor' => $this->rentMinor,
            'payment_frequency' => $this->paymentFrequency,
            'payment_day' => $this->paymentDay,
            'source_document_id' => $this->sourceDocumentId,
            'years' => $this->years,
        ];
    }
}
