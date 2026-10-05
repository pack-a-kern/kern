<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\DestroySpvSession;

/**
 * `DELETE .../spv/sessions/{session}` — close the browser session.
 *
 * Called when a person abandons the sign-in as well as when it finishes: a browser left open on a
 * half-authenticated portal is the thing a timeout would eventually clear, and waiting for a
 * timeout is not a reason to leave it open.
 */
final class DestroySpvSessionController
{
    public function __invoke(Request $request, string $subject, string $session, DestroySpvSession $destroy): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $destroy($session, $user->id);

        return response()->json(['state' => $result->state->value]);
    }
}
