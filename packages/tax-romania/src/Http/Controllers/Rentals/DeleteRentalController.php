<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Rentals;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Rental\Actions\DeleteRental;
use Kern\TaxRomania\Rental\Models\RentalContract;

/**
 * `DELETE /api/v1/subjects/{subject}/rentals/{rental}` — remove a rental entered by mistake.
 */
final class DeleteRentalController
{
    public function __invoke(Request $request, string $subject, string $rental, DeleteRental $delete): JsonResponse
    {
        $contract = RentalContract::query()->findOrFail($rental);
        $actor = $request->user()?->getAuthIdentifier();

        $delete($contract, is_scalar($actor) ? (string) $actor : null);

        return response()->json([], 204);
    }
}
