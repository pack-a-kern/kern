<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\DataTransferObjects\TerminateRentalContractInput;
use App\Domain\Rental\Enums\RentalContractStatus;
use App\Domain\Rental\Exceptions\ContractAlreadyTerminatedException;
use App\Domain\Rental\Exceptions\InvalidDateRangeException;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Models\RentalContractVersion;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Closes a contract: sets `end_date`, closes the currently in-force version's `valid_to` to that
 * same date, and marks the contract `TERMINATED`. After this, `App\Domain\Rental\Actions\
 * AmendRentalContract` refuses to record any further amendment (`ContractAlreadyTerminatedException`).
 */
final readonly class TerminateRentalContract
{
    public function __invoke(TerminateRentalContractInput $input): RentalContract
    {
        return DB::transaction(function () use ($input): RentalContract {
            /** @var RentalContract $contract */
            $contract = RentalContract::query()->findOrFail($input->rentalContractId);

            if ($contract->status === RentalContractStatus::TERMINATED) {
                throw ContractAlreadyTerminatedException::forContract($contract->id);
            }

            $endDate = new DateTimeImmutable($input->endDate);
            $startDate = $contract->start_date->toImmutable();

            if (($endDate <=> $startDate) <= 0) {
                throw InvalidDateRangeException::endNotAfterStart($contract->start_date->format('Y-m-d'), $input->endDate);
            }

            /** @var RentalContractVersion|null $current */
            $current = RentalContractVersion::query()
                ->where('rental_contract_id', $contract->id)
                ->whereNull('valid_to')
                ->first();

            if ($current === null) {
                throw new RuntimeException("Rental contract {$contract->id} has no currently in-force version.");
            }

            $current->valid_to = Carbon::parse($input->endDate);
            $current->save();

            $contract->end_date = Carbon::parse($input->endDate);
            $contract->status = RentalContractStatus::TERMINATED;
            $contract->save();

            return $contract;
        });
    }
}
