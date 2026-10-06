<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\RevokeSpvConnection;

/**
 * `DELETE .../spv/connections/{connection}` — stop using this connection.
 *
 * Revoked rather than deleted: the row is what the sync runs and the audit events point at, and
 * removing it would leave a history of calls to a connection nothing can name. A revoked connection
 * is not offered for signing in, and registering the same username again is a new one.
 */
final class RevokeSpvConnectionController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $connection,
        RevokeSpvConnection $revoke,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $result = $revoke($connection, $user->id);

        return response()->json(['status' => $result->status->value]);
    }
}
