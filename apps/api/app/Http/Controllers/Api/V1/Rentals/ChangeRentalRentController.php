<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Domain\Rental\Actions\ChangeRentalRent;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Exceptions\ContractAlreadyTerminatedException;
use App\Domain\Rental\Exceptions\InvalidAmendmentDateException;
use App\Domain\Rental\Models\RentalContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/subjects/{subject}/rentals/{rental}/rent` — a new rent from a date.
 */
final class ChangeRentalRentController extends Controller
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
