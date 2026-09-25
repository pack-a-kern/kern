<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Rentals;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Rental\Actions\EndRental;
use Kern\TaxRomania\Rental\Exceptions\ContractAlreadyTerminatedException;
use Kern\TaxRomania\Rental\Exceptions\InvalidDateRangeException;
use Kern\TaxRomania\Rental\Models\RentalContract;

/**
 * `POST /api/v1/subjects/{subject}/rentals/{rental}/end` — the rental ends on a date.
 */
final class EndRentalController
{
    public function __invoke(Request $request, string $subject, string $rental, EndRental $end): JsonResponse
    {
        $contract = RentalContract::query()->findOrFail($rental);

        $validated = $request->validate(['end_date' => ['required', 'date']]);
        $actor = $request->user()?->getAuthIdentifier();

        try {
            $end(
                $contract,
                is_string($validated['end_date']) ? $validated['end_date'] : '',
                is_scalar($actor) ? (string) $actor : null,
            );
        } catch (ContractAlreadyTerminatedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'RENTAL_ENDED'], 409);
        } catch (InvalidDateRangeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'RENTAL_END_DATE'], 422);
        }

        return response()->json(['id' => $contract->id]);
    }
}
