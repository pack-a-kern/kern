<?php

declare(strict_types=1);

namespace App\Domain\Rental\Actions;

use App\Domain\Rental\DataTransferObjects\RentPeriod;
use App\Domain\Rental\Events\RentalAccrualRecorded;
use App\Domain\Rental\Models\RentalAccrual;
use App\Domain\Rental\Models\RentalContract;
use App\Domain\Rental\Services\RentSchedule;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * (Re)computes a rental contract's `rental_accruals` through `$until` (exclusive) and persists them,
 * dispatching {@see RentalAccrualRecorded} for each period after the transaction commits.
 *
 * An accrual is rent the contract makes **owed** for a period — the income a tax return counts for a
 * rental, which is set by the contract "indiferent de momentul încasării chiriei" (Codul fiscal,
 * art. 84). Whether the tenant paid is not recorded here, because it does not change the figure.
 *
 * The periods themselves come from {@see RentSchedule}, which the rentals screen also reads directly;
 * this action is only what stores them.
 *
 * **Idempotent and safe to re-run**, matched by the unique `(rental_contract_id, period_start,
 * period_end)` key: a period with no row is inserted, a period with a row is recalculated in place,
 * and a row the current terms no longer produce is deleted. The last matters: an amendment or an
 * ending moves period boundaries, and without it the old periods stayed beside the new ones — the
 * same fault a deposit's interest schedule had until it gained the same rule.
 *
 * Requires an active `Kern\Platform\Tenancy\TenantContext` authorized for the contract's own
 * `tax_subject_id` — every model this action reads/writes is tenant-scoped.
 */
final readonly class GenerateRentalAccruals
{
    public function __construct(private RentSchedule $schedule)
    {
    }

    /**
     * @return list<RentalAccrual>
     */
    public function __invoke(RentalContract $contract, DateTimeImmutable $until): array
    {
        $property = $contract->property()->firstOrFail();
        $periods = $this->schedule->periods($contract, $property, $contract->versions()->get(), $until);

        $accruals = DB::transaction(function () use ($contract, $periods): array {
            $recorded = array_map(fn (RentPeriod $period): RentalAccrual => $this->upsert($contract, $period), $periods);

            RentalAccrual::query()
                ->where('rental_contract_id', $contract->id)
                ->whereNotIn('id', array_map(static fn (RentalAccrual $accrual): string => $accrual->id, $recorded))
                ->delete();

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

    private function upsert(RentalContract $contract, RentPeriod $period): RentalAccrual
    {
        $attributes = [
            'tax_subject_id' => $contract->tax_subject_id,
            'rental_contract_id' => $contract->id,
            'rental_contract_version_id' => $period->version->id,
            'period_start' => $period->periodStart->format('Y-m-d'),
            'period_end' => $period->periodEnd->format('Y-m-d'),
            'due_date' => $period->dueDate->format('Y-m-d'),
            'gross_minor' => $period->shareMinor,
        ];

        $existing = RentalAccrual::query()
            ->where('rental_contract_id', $contract->id)
            ->whereDate('period_start', $attributes['period_start'])
            ->whereDate('period_end', $attributes['period_end'])
            ->first();

        if ($existing === null) {
            return RentalAccrual::create($attributes);
        }

        $existing->fill($attributes);
        $existing->save();

        return $existing;
    }
}
