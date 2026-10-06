<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\DeleteSpvConnection;
use Symfony\Component\HttpFoundation\Response;

/**
 * `DELETE .../spv/connections/{connection}` — remove this connection and its sync history.
 *
 * 204, not the row: there is nothing left to describe. {@see DeleteSpvConnection} for what cascades
 * and what deliberately does not.
 */
final class DeleteSpvConnectionController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $connection,
        DeleteSpvConnection $delete,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $delete($connection, $user->id);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
