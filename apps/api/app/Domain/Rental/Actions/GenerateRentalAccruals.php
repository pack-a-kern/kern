<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\Enums\AccrualStatus;
use App\Domain\Rental\Events\RentalAccrualRecorded;
use App\Domain\Rental\Models\RentalAccrual;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Models\RentalContractVersion;
use App\Domain\Rental\Services\AccrualPeriodBuilder;
use App\Domain\Rental\Services\OwnershipShareCalculator;
use App\Domain\Rental\Services\RentalDueDateCalculator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * (Re)computes a rental contract's `rental_accruals` through `$until` (exclusive) and persists
 * them, dispatching `App\Domain\Rental\Events\RentalAccrualRecorded` for each period after the
 * transaction commits — the exact idempotent-regeneration idiom `App\Domain\Wallet\Actions\
 * GenerateDepositInterestSchedule` establishes for `deposit_interest_events`.
 *
 * **The temporal model that makes an amendment produce correct accruals on both sides**: every
 * `rental_contract_versions` row is scheduled *independently*, from its own `valid_from` to
 * `min(valid_to ?? $until, $until)`, via `App\Domain\Rental\Services\AccrualPeriodBuilder`. A rent
 * change recorded by `App\Domain\Rental\Actions\AmendRentalContract` always lands exactly on a
 * period boundary for this reason — the old version's schedule stops there and the new version's
 * schedule starts there, so no single `rental_accruals` row is ever asked to represent two
 * different rent amounts.
 *
 * **Idempotent and safe to re-run**, matched by the unique `(rental_contract_id, period_start,
 * period_end)` key, with the identical three-way behaviour `GenerateDepositInterestSchedule`
 * documents: a period with no existing row is inserted; an existing, not-yet-`PAID` row is
 * recalculated and updated in place; an existing `PAID` row is left completely untouched — a
 * settled accrual is immutable (`docs/AGENTS.md` §1 rule 17's spirit).
 *
 * Requires an active `App\Domain\Subjects\Tenancy\TenantContext` authorized for the contract's own
 * `tax_subject_id` — every model this action reads/writes is tenant-scoped.
 */
final readonly class GenerateRentalAccruals
{
    public function __construct(
        private AccrualPeriodBuilder $periodBuilder,
        private OwnershipShareCalculator $shareCalculator,
        private RentalDueDateCalculator $dueDateCalculator,
    ) {
    }

    /**
     * @return list<RentalAccrual>
     */
    public function __invoke(RentalContract $contract, DateTimeImmutable $until): array
    {
        $property = $contract->property()->firstOrFail();
        $versions = $contract->versions()->get();

        $accruals = DB::transaction(function () use ($contract, $property, $versions, $until): array {
            $recorded = [];

            foreach ($versions as $version) {
                /** @var RentalContractVersion $version */
                $validFrom = $version->valid_from->toImmutable();
                $validTo = $version->valid_to?->toImmutable() ?? $until;
                $effectiveEnd = $validTo < $until ? $validTo : $until;

                if (($effectiveEnd <=> $validFrom) <= 0) {
                    continue;
                }

                foreach ($this->periodBuilder->build($version->payment_frequency, $validFrom, $effectiveEnd) as [$periodStart, $periodEnd]) {
                    $recorded[] = $this->upsertPeriod($contract, $version, $property->ownership_percent_decimal, $periodStart, $periodEnd);
                }
            }

            return $recorded;
        });

        foreach ($accruals as $accrual) {
            event(new RentalAccrualRecorded(
                rentalAccrualId: $accrual->id,
                taxSubjectId: $accrual->tax_subject_id,
                propertyId: $property->id,
                rentalContractId: $accrual->rental_contract_id,
                tenantType: $contract->tenant_type,
                periodStart: $accrual->period_start->toImmutable(),
                periodEnd: $accrual->period_end->toImmutable(),
                dueDate: $accrual->due_date->toImmutable(),
                currencyCode: $contract->currency_code,
                grossMinor: $accrual->gross_minor,
                ownershipPercentDecimal: $property->ownership_percent_decimal,
            ));
        }

        return $accruals;
    }

    private function upsertPeriod(
        RentalContract $contract,
        RentalContractVersion $version,
        string $ownershipPercentDecimal,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): RentalAccrual {
        /** @var RentalAccrual|null $existing */
        $existing = RentalAccrual::query()
            ->where('rental_contract_id', $contract->id)
            ->whereDate('period_start', $periodStart->format('Y-m-d'))
            ->whereDate('period_end', $periodEnd->format('Y-m-d'))
            ->first();

        if ($existing !== null && $existing->status === AccrualStatus::PAID) {
            return $existing;
        }

        $grossMinor = $this->shareCalculator->shareOf($version->rent_minor, $ownershipPercentDecimal);
        $dueDate = $this->dueDateCalculator->forPeriod($periodStart, $periodEnd, $version->payment_day);

        $attributes = [
            'tax_subject_id' => $contract->tax_subject_id,
            'rental_contract_id' => $contract->id,
            'rental_contract_version_id' => $version->id,
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
            'due_date' => $dueDate->format('Y-m-d'),
            'gross_minor' => $grossMinor,
        ];

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        return RentalAccrual::create($attributes);
    }
}
