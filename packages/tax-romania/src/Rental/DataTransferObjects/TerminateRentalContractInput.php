<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\DataTransferObjects;

final readonly class TerminateRentalContractInput
{
    public function __construct(
        public string $rentalContractId,
        public string $taxSubjectId,
        public string $endDate,
    ) {
    }
}
