<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Rentals;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Rental\Actions\ChangeRentalRent;
use Kern\TaxRomania\Rental\Enums\RentalPaymentFrequency;
use Kern\TaxRomania\Rental\Exceptions\ContractAlreadyTerminatedException;
use Kern\TaxRomania\Rental\Exceptions\InvalidAmendmentDateException;
use Kern\TaxRomania\Rental\Models\RentalContract;

/**
 * `POST /api/v1/subjects/{subject}/rentals/{rental}/rent` — a new rent from a date.
 */
final class ChangeRentalRentController
{
    public function __invoke(Request $request, string $subject, string $rental, ChangeRentalRent $change): JsonResponse
    {
        $contract = RentalContract::query()->findOrFail($rental);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'rent_minor' => ['required', 'integer', 'min:1'],
            'payment_frequency' => ['required', Rule::enum(RentalPaymentFrequency::class)],
            'payment_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
        ]);

        $actor = $request->user()?->getAuthIdentifier();

        try {
            $change(
                contract: $contract,
                effectiveFrom: is_string($validated['effective_from']) ? $validated['effective_from'] : '',
                rentMinor: is_numeric($validated['rent_minor']) ? (int) $validated['rent_minor'] : 0,
                paymentFrequency: RentalPaymentFrequency::from(is_string($validated['payment_frequency']) ? $validated['payment_frequency'] : ''),
                paymentDay: is_numeric($validated['payment_day'] ?? null) ? (int) $validated['payment_day'] : null,
                actorId: is_scalar($actor) ? (string) $actor : null,
            );
        } catch (ContractAlreadyTerminatedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'RENTAL_ENDED'], 409);
        } catch (InvalidAmendmentDateException $exception) {
            // The new rent must start after the current one did, and before the rental ends.
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'RENTAL_CHANGE_DATE'], 422);
        }

        return response()->json(['id' => $contract->id]);
    }
}
