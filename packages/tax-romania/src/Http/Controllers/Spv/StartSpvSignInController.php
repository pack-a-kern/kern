<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\InitiateSpvAuthAttempt;

/**
 * `POST .../spv/connections/{connection}/session` — open a browser session for a portal sign-in.
 *
 * The first of three steps a person takes in one sitting, because the worker holds a real browser
 * open between them: this opens it, the next sends the credentials, the third sends the code ANAF
 * emails. Nothing is remembered afterwards — the password is never stored, so the sitting happens
 * again at the next sync.
 */
final class StartSpvSignInController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $connection,
        InitiateSpvAuthAttempt $initiate,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $session = $initiate($user, $connection);

        return response()->json([
            'session_id' => $session->id,
            'state' => $session->state->value,
            'expires_at' => $session->expires_at->toAtomString(),
        ], 201);
    }
}
