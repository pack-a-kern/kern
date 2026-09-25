<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\DataTransferObjects;

use Kern\TaxRomania\Rental\Enums\PropertyType;
use Kern\TaxRomania\Rental\Enums\RentalPaymentFrequency;
use Kern\TaxRomania\Rental\Enums\TenantType;

/**
 * A rental as a person enters it: the property and the contract on it, in one form.
 *
 * The model keeps the two apart — a property outlives its tenants — but nobody thinks of letting a
 * flat as two steps, so opening one takes both at once.
 */
final readonly class OpenRentalInput
{
    public function __construct(
        public string $taxSubjectId,
        public PropertyType $propertyType,
        public string $address,
        /** Decimal string, e.g. "50.00" — only this share of the rent is this taxpayer's income. */
        public string $ownershipPercentDecimal,
        public TenantType $tenantType,
        public string $tenantName,
        public string $startDate,
        public ?string $endDate,
        public string $currencyCode,
        public int $rentMinor,
        public RentalPaymentFrequency $paymentFrequency,
        public ?int $paymentDay = null,
        /** The contract document it was read from, when there was one. */
        public ?string $sourceDocumentId = null,
        public ?string $extractionId = null,
        public ?string $actorId = null,
    ) {
    }
}
