<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\DataTransferObjects\RegisterPropertyInput;
use App\Domain\Rental\Exceptions\InvalidOwnershipPercentException;
use App\Domain\Rental\Models\Property;

/**
 * Registers one `properties` row. Validates `ownership_percent_decimal` eagerly — every accrual
 * `App\Domain\Rental\Actions\GenerateRentalAccruals` ever computes for this property trusts this
 * value without re-validating it (`BLUEPRINT.md` §33's co-ownership constraint depends on it always
 * being a trustworthy share).
 */
final readonly class RegisterProperty
{
    private const string OWNERSHIP_PERCENT_PATTERN = '/^\d{1,3}(\.\d{1,4})?$/';

    public function __invoke(RegisterPropertyInput $input): Property
    {
        $this->assertValidOwnershipPercent($input->ownershipPercentDecimal);

        return Property::create([
            'tax_subject_id' => $input->taxSubjectId,
            'type' => $input->type,
            'address_ciphertext' => $input->address,
            'cadastral_ref_ciphertext' => $input->cadastralReference,
            'ownership_percent_decimal' => $input->ownershipPercentDecimal,
            'acquired_at' => $input->acquiredAt,
        ]);
    }

    private function assertValidOwnershipPercent(string $value): void
    {
        if (preg_match(self::OWNERSHIP_PERCENT_PATTERN, $value) !== 1) {
            throw InvalidOwnershipPercentException::forValue($value);
        }

        assert(is_numeric($value));

        if (bccomp($value, '0', 4) <= 0 || bccomp($value, '100', 4) > 0) {
            throw InvalidOwnershipPercentException::forValue($value);
        }
    }
}
