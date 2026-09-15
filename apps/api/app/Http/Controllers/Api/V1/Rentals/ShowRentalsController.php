<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Domain\Rental\Enums\PropertyType;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Enums\TenantType;
use App\Domain\Rental\Queries\RentalQuery;
use App\Domain\Rental\Queries\RentalSummary;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/subjects/{subject}/rentals` — rentals, what each owes per year, and the form's choices.
 *
 * The choices travel with the list for the same reason the wallet's deposit options do: they are the
 * server's enums, and a copy of them in TypeScript would drift the first time one gained a case.
 */
final class ShowRentalsController extends Controller
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
