<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\DataTransferObjects;

use Kern\TaxRomania\Rental\Enums\PropertyType;
use Kern\TaxRomania\Rental\Enums\TenantType;

/**
 * The parts of a rental that are descriptions rather than terms. Null means "leave it alone".
 *
 * The rent, its frequency and the dates are not here: a changed rent starts on a date and keeps the
 * old one before it ({@see \Kern\TaxRomania\Rental\Actions\ChangeRentalRent}), and an ending is its own act
 * ({@see \Kern\TaxRomania\Rental\Actions\EndRental}). Editing either in place would rewrite what earlier
 * years were owed.
 */
final readonly class UpdateRentalDetailsInput
{
    public function __construct(
        public ?PropertyType $propertyType = null,
        public ?string $address = null,
        public ?string $ownershipPercentDecimal = null,
        public ?TenantType $tenantType = null,
        public ?string $tenantName = null,
        public ?string $actorId = null,
    ) {
    }
}
