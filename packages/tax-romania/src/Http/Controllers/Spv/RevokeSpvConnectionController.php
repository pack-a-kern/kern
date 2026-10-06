<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\RevokeSpvConnection;

/**
 * `POST .../spv/connections/{connection}/revocation` — stop using this connection, keep what it did.
 *
 * Revoking keeps the row, because the sync runs point at it and a history of calls to a connection
 * nothing can name is worse than a tidy list. A revoked connection is not offered for signing in, and
 * registering the same username again makes a new one.
 *
 * `POST` on a sub-resource rather than `DELETE` on the connection, now that
 * {@see DeleteSpvConnectionController} holds that verb: revoking creates a state, deleting removes a
 * row, and one URL cannot mean both.
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
