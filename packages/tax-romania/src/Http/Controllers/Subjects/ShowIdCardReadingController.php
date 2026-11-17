<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\IdCard\Services\IdCardScratch;

/**
 * `GET /api/v1/subjects/id-card/read/{token}` — the result of a read, fetched once over HTTPS after
 * the socket nudge (ADR 0028).
 *
 * This is where the fields travel, never the broadcast: the CNP, name and address come back here,
 * through ordinary authentication, bound to the user who uploaded the card. The result is single-use
 * — taken and deleted in the same step — and a token that is not this user's reveals nothing and is
 * left in place, so a guess cannot consume the real owner's one read.
 *
 * A read that has expired, been taken already, or never belonged to this user is simply gone: `404`,
 * which the form treats as "type it by hand".
 */
final class ShowIdCardReadingController
{
    public function __invoke(Request $request, string $token, IdCardScratch $scratch): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $result = $scratch->pullResult($token, $user->id);

        if ($result === null) {
            return response()->json(['status' => 'expired'], 404);
        }

        return response()->json($result);
    }
}
