<?php

declare(strict_types=1);

namespace App\Domain\Rental\DataTransferObjects;

use App\Domain\Rental\Models\RentalContractVersion;
use DateTimeImmutable;

/**
 * One period of rent a contract says is owed, and this taxpayer's share of it.
 *
 * Owed, not received: rental income is established by the contract for each year "indiferent de
 * momentul încasării chiriei" (Codul fiscal, art. 84), so whether the tenant actually paid is not
 * part of this figure at all.
 */
final readonly class RentPeriod
{
    public function __construct(
        /** The terms this period was computed from — an amendment starts a new one on a period boundary. */
        public RentalContractVersion $version,
        public DateTimeImmutable $periodStart,
        /** Exclusive. */
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $dueDate,
        /** This taxpayer's share of the period's rent, in minor units of the contract's currency. */
        public int $shareMinor,
    ) {
    }
}
