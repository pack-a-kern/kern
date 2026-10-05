<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\SubmitSpvCredentials;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvCredentialsInput;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;

/**
 * `POST .../spv/sessions/{session}/credentials` — the username and password, passed through.
 *
 * ## Why this is synchronous
 *
 * A queued job serialises its arguments into Redis, which would be an SPV password at rest — the
 * one thing `SpvCredentialPolicy` exists to forbid. So the credentials travel in the request that
 * carries them and in nothing else: wrapped in {@see SensitiveString} as they leave the request,
 * and unwrapped only at the single sanctioned crossing into the browser worker.
 *
 * The validated array is never logged or returned. The response says what the portal answered and
 * nothing about what was sent.
 */
final class SubmitSpvCredentialsController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $session,
        SubmitSpvCredentials $submit,
    ): JsonResponse {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $result = $submit(new SubmitSpvCredentialsInput(
            sessionId: $session,
            username: (string) $validated['username'],
            password: SensitiveString::wrap((string) $validated['password']),
            actorId: $user->id,
        ));

        return response()->json([
            'state' => $result->state->value,
            'expires_at' => $result->expires_at->toAtomString(),
        ]);
    }
}
