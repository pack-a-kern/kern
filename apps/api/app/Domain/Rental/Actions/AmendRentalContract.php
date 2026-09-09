<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\DataTransferObjects\AmendRentalContractInput;
use App\Domain\Rental\Enums\RentalContractStatus;
use App\Domain\Rental\Exceptions\ContractAlreadyTerminatedException;
use App\Domain\Rental\Exceptions\InvalidAmendmentDateException;
use App\Domain\Rental\Exceptions\NonPositiveRentException;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Models\RentalContractVersion;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records an amendment: closes the currently in-force `rental_contract_versions` row at the
 * amendment's effective date and opens a new one from that date onward. This is what makes a
 * mid-period rent change produce correct accruals on both sides — `App\Domain\Rental\Actions\
 * GenerateRentalAccruals` schedules each version independently from its own `valid_from`, so a
 * rent change never straddles a single accrual row.
 *
 * Only ever appends to the end of a contract's timeline — `$input->effectiveFrom` must be
 * strictly after the current version's own `valid_from` and, if the contract has an `end_date`,
 * strictly before it. Backdating an amendment into the middle of already-amended history is not
 * supported (not asked for by this task).
 */
final readonly class AmendRentalContract
{
    public function __invoke(AmendRentalContractInput $input): RentalContractVersion
    {
        if ($input->rentMinor <= 0) {
            throw NonPositiveRentException::forAmount($input->rentMinor);
        }

        return DB::transaction(function () use ($input): RentalContractVersion {
            /** @var RentalContract $contract */
            $contract = RentalContract::query()->findOrFail($input->rentalContractId);

            if ($contract->status === RentalContractStatus::TERMINATED) {
                throw ContractAlreadyTerminatedException::forContract($contract->id);
            }

            /** @var RentalContractVersion|null $current */
            $current = RentalContractVersion::query()
                ->where('rental_contract_id', $contract->id)
                ->whereNull('valid_to')
                ->first();

            if ($current === null) {
                throw new RuntimeException("Rental contract {$contract->id} has no currently in-force version.");
            }

            $effectiveFrom = new DateTimeImmutable($input->effectiveFrom);
            $currentValidFrom = $current->valid_from->toImmutable();

            if (($effectiveFrom <=> $currentValidFrom) <= 0) {
                throw InvalidAmendmentDateException::notAfterCurrentVersion(
                    $input->effectiveFrom,
                    $current->valid_from->format('Y-m-d'),
                );
            }

            if ($contract->end_date !== null) {
                $endDate = $contract->end_date->toImmutable();

                if (($effectiveFrom <=> $endDate) >= 0) {
                    throw InvalidAmendmentDateException::notBeforeContractEnd(
                        $input->effectiveFrom,
                        $contract->end_date->format('Y-m-d'),
                    );
                }
            }

            $current->valid_to = Carbon::parse($input->effectiveFrom);
            $current->save();

            return RentalContractVersion::create([
                'tax_subject_id' => $input->taxSubjectId,
                'rental_contract_id' => $contract->id,
                'valid_from' => $input->effectiveFrom,
                'valid_to' => null,
                'rent_minor' => $input->rentMinor,
                'payment_frequency' => $input->paymentFrequency,
                'payment_day' => $input->paymentDay,
                'terms_ciphertext' => $input->terms,
                'source_document_id' => $input->sourceDocumentId,
            ]);
        });
    }
}
