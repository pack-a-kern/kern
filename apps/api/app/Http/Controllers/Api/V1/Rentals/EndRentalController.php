<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Domain\Rental\Actions\EndRental;
use App\Domain\Rental\Exceptions\ContractAlreadyTerminatedException;
use App\Domain\Rental\Exceptions\InvalidDateRangeException;
use App\Domain\Rental\Models\RentalContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/{subject}/rentals/{rental}/end` — the rental ends on a date.
 */
final class EndRentalController extends Controller
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
