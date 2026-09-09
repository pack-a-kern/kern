<?php

declare(strict_types=1);

namespace App\Domain\Rental\DataTransferObjects;

use App\Domain\Rental\Enums\PropertyType;

final readonly class RegisterPropertyInput
{
    public function __construct(
        public string $taxSubjectId,
        public PropertyType $type,
        public string $address,
        public string $ownershipPercentDecimal,
        public ?string $cadastralReference = null,
        public ?string $acquiredAt = null,
    ) {
    }
}
