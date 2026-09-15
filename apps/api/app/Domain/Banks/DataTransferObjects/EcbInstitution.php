<?php

declare(strict_types=1);

namespace App\Domain\Banks\DataTransferObjects;

/**
 * One row of the ECB's list of financial institutions, as printed. Empty strings where the file has
 * no value — a branch has no LEI of its own, a bank that is not a branch has no parent.
 */
final readonly class EcbInstitution
{
    public function __construct(
        public string $ecbCode,
        public string $lei,
        public string $countryCode,
        public string $name,
        public string $address,
        public string $postalCode,
        public string $city,
        public string $category,
        public string $parentName,
        public string $parentLei,
        public string $parentCountryCode,
    ) {
    }
}
