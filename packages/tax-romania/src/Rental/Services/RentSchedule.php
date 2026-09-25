<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Services;

use DateTimeImmutable;
use Kern\TaxRomania\Rental\DataTransferObjects\RentPeriod;
use Kern\TaxRomania\Rental\Models\Property;
use Kern\TaxRomania\Rental\Models\RentalContract;
use Kern\TaxRomania\Rental\Models\RentalContractVersion;

/**
 * Every period of rent a contract makes owed, up to a date — computed, never stored.
 *
 * The one place that turns a contract's versions into periods, due dates and this taxpayer's share.
 * `GenerateRentalAccruals` persists what it returns; the rentals screen reads it directly to show
 * what each year's rent comes to, without writing anything to answer a question.
 *
 * Each version is scheduled on its own, from its `valid_from` to the earlier of its `valid_to` and
 * `$until`. An amendment always lands on a period boundary for that reason: the old terms stop there
 * and the new ones start there, so no period is ever asked to hold two different rents.
 */
final readonly class RentSchedule
{
    public function __construct(
        private AccrualPeriodBuilder $periodBuilder,
        private OwnershipShareCalculator $shareCalculator,
        private RentalDueDateCalculator $dueDateCalculator,
    ) {
    }

    /**
     * @param  iterable<RentalContractVersion>  $versions  the contract's versions, in order
     * @param  DateTimeImmutable  $until  exclusive
     * @return list<RentPeriod>
     */
    public function periods(RentalContract $contract, Property $property, iterable $versions, DateTimeImmutable $until): array
    {
        $periods = [];

        foreach ($versions as $version) {
            $validFrom = $version->valid_from->toImmutable();
            $validTo = $version->valid_to?->toImmutable() ?? $until;
            $effectiveEnd = $validTo < $until ? $validTo : $until;

            if ($effectiveEnd <= $validFrom) {
                continue;
            }

            foreach ($this->periodBuilder->build($version->payment_frequency, $validFrom, $effectiveEnd) as [$start, $end]) {
                $periods[] = new RentPeriod(
                    version: $version,
                    periodStart: $start,
                    periodEnd: $end,
                    dueDate: $this->dueDateCalculator->forPeriod($start, $end, $version->payment_day),
                    shareMinor: $this->shareCalculator->shareOf($version->rent_minor, $property->ownership_percent_decimal),
                );
            }
        }

        return $periods;
    }
}
