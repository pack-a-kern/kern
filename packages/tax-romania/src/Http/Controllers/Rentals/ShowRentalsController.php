<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Rentals;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Rental\Enums\PropertyType;
use Kern\TaxRomania\Rental\Enums\RentalPaymentFrequency;
use Kern\TaxRomania\Rental\Enums\TenantType;
use Kern\TaxRomania\Rental\Queries\RentalQuery;
use Kern\TaxRomania\Rental\Queries\RentalSummary;

/**
 * `GET /api/v1/subjects/{subject}/rentals` — rentals, what each owes per year, and the form's choices.
 *
 * The choices travel with the list for the same reason the wallet's deposit options do: they are the
 * server's enums, and a copy of them in TypeScript would drift the first time one gained a case.
 */
final class ShowRentalsController
{
    public function __invoke(RentalQuery $rentals): JsonResponse
    {
        return response()->json([
            'rentals' => array_map(static fn (RentalSummary $rental): array => $rental->toArray(), $rentals->rentals()),
            'form_options' => [
                'property_types' => array_map(static fn (PropertyType $type): string => $type->value, PropertyType::cases()),
                // `UNKNOWN` is for a contract read from a document that did not say; a person entering
                // a rental knows whether they let to a person or a company.
                'tenant_types' => array_values(array_diff(
                    array_map(static fn (TenantType $type): string => $type->value, TenantType::cases()),
                    [TenantType::UNKNOWN->value],
                )),
                'payment_frequencies' => array_map(
                    static fn (RentalPaymentFrequency $frequency): string => $frequency->value,
                    RentalPaymentFrequency::cases(),
                ),
            ],
        ]);
    }
}
