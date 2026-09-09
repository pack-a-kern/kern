<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\DataTransferObjects\CreateRentalContractInput;
use App\Domain\Rental\Enums\RentalContractStatus;
use App\Domain\Rental\Exceptions\InvalidDateRangeException;
use App\Domain\Rental\Exceptions\NonPositiveRentException;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Models\RentalContractVersion;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens a `rental_contracts` row plus its first `rental_contract_versions` row (`valid_from =
 * start_date`, `valid_to = null` — the original terms, in force until the first amendment, if
 * any). Rejects a non-positive rent or an inverted date range before anything is written.
 */
final readonly class CreateRentalContract
{
    public function __invoke(CreateRentalContractInput $input): RentalContract
    {
        if ($input->rentMinor <= 0) {
            throw NonPositiveRentException::forAmount($input->rentMinor);
        }

        $startDate = new DateTimeImmutable($input->startDate);

        if ($input->endDate !== null) {
            $endDate = new DateTimeImmutable($input->endDate);

            if (($endDate <=> $startDate) <= 0) {
                throw InvalidDateRangeException::endNotAfterStart($input->startDate, $input->endDate);
            }
        }

        return DB::transaction(function () use ($input): RentalContract {
            $contract = RentalContract::create([
                'tax_subject_id' => $input->taxSubjectId,
                'property_id' => $input->propertyId,
                'tenant_type' => $input->tenantType,
                'tenant_identity_ciphertext' => $input->tenantIdentity,
                'start_date' => $input->startDate,
                'end_date' => $input->endDate,
                'currency_code' => strtoupper($input->currencyCode),
                'deposit_minor' => $input->depositMinor,
                'status' => RentalContractStatus::ACTIVE,
                'source_document_id' => $input->sourceDocumentId,
                'extraction_id' => $input->extractionId,
            ]);

            RentalContractVersion::create([
                'tax_subject_id' => $input->taxSubjectId,
                'rental_contract_id' => $contract->id,
                'valid_from' => $input->startDate,
                'valid_to' => null,
                'rent_minor' => $input->rentMinor,
                'payment_frequency' => $input->paymentFrequency,
                'payment_day' => $input->paymentDay,
                'source_document_id' => $input->sourceDocumentId,
            ]);

            return $contract;
        });
    }
}
