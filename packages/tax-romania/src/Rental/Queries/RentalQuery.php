<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Queries;

use Kern\TaxRomania\Rental\Models\RentalContract;
use Kern\TaxRomania\Rental\Models\RentalContractVersion;
use Kern\TaxRomania\Rental\Services\RentalAccrualHorizon;
use Kern\TaxRomania\Rental\Services\RentSchedule;
use RuntimeException;

/**
 * Rentals for the active taxpayer, with what each contract owes per year.
 *
 * ## Owed, not received
 *
 * The yearly figure is the contract's rent for the periods falling due in that year, times this
 * taxpayer's ownership share — computed from the terms, never from money that arrived. Rental income
 * is set by the contract "indiferent de momentul încasării chiriei" (Codul fiscal, art. 84), so a
 * tenant paying late or not at all does not move it.
 *
 * It is computed here on every read, from {@see RentSchedule}, rather than summed from stored
 * accruals: a question about a rental should not depend on whether something regenerated them since
 * the year turned. What it is *not* is a tax figure — how a partial first period counts and how a
 * foreign-currency rent converts are the tax engine's sourced rules, applied to these amounts.
 */
final readonly class RentalQuery
{
    public function __construct(private RentSchedule $schedule)
    {
    }

    /**
     * @return list<RentalSummary>
     */
    public function rentals(): array
    {
        /** @var list<RentalContract> $contracts */
        $contracts = RentalContract::query()
            ->with(['property', 'versions'])
            ->orderByDesc('start_date')
            ->get()
            ->all();

        return array_map(fn (RentalContract $contract): RentalSummary => $this->summarise($contract), $contracts);
    }

    private function summarise(RentalContract $contract): RentalSummary
    {
        $property = $contract->property;

        if ($property === null) {
            // Unreachable: `property_id` is a non-null foreign key with a cascade. Restated so the
            // reads below are provably non-null rather than assumed to be.
            throw new RuntimeException("Rental contract {$contract->id} has no property.");
        }

        $versions = $contract->versions;
        $current = $versions->last(static fn (RentalContractVersion $version): bool => $version->valid_to === null)
            ?? $versions->last();

        if ($current === null) {
            throw new RuntimeException("Rental contract {$contract->id} has no terms.");
        }

        $owed = [];

        foreach ($this->schedule->periods($contract, $property, $versions, RentalAccrualHorizon::for($contract)) as $period) {
            // By the date a period falls due: that is the year the contract makes it owed in.
            $year = (int) $period->dueDate->format('Y');
            $owed[$year] = ($owed[$year] ?? 0) + $period->shareMinor;
        }

        krsort($owed);

        return new RentalSummary(
            id: $contract->id,
            propertyType: $property->type->value,
            address: (string) $property->address_ciphertext,
            ownershipPercent: $property->ownership_percent_decimal,
            tenantType: $contract->tenant_type->value,
            tenantName: (string) $contract->tenant_identity_ciphertext,
            startDate: $contract->start_date->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            status: $contract->status->value,
            currencyCode: $contract->currency_code,
            rentMinor: $current->rent_minor,
            paymentFrequency: $current->payment_frequency->value,
            paymentDay: $current->payment_day,
            sourceDocumentId: $contract->source_document_id,
            years: array_map(
                static fn (int $year, int $share): array => ['year' => $year, 'share_minor' => $share],
                array_keys($owed),
                array_values($owed),
            ),
        );
    }
}
