<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Domain\Rental\Actions\DeleteRental;
use App\Domain\Rental\Models\RentalContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
