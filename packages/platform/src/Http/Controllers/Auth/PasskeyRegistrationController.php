<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Identity\Actions\CompletePasskeyRegistration;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\ChallengeExpiredException;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use Kern\Platform\Identity\Support\Webauthn\PasskeyManager;

/**
 * `POST /api/v1/auth/passkeys/registration/options` and `.../registration` — behind the `auth:web`
 * guard (registering a *new* passkey requires an already-authenticated session; there is no
 * "register a passkey for an account you have not proven you control some other way" path).
 */
final class PasskeyRegistrationController
{
    public function options(Request $request, PasskeyManager $passkeys): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();

        return response()->json($passkeys->creationOptionsFor($user));
    }

    public function store(Request $request, PasskeyManager $passkeys, CompletePasskeyRegistration $complete): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'array'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();

        try {
            $passkey = $complete($user, $data['credential'], $request->getHost(), $data['name'] ?? null);
        } catch (ChallengeExpiredException|PasskeyVerificationException $exception) {
            throw ValidationException::withMessages(['credential' => [$exception->getMessage()]]);
        }

        return response()->json(['id' => $passkey->id], 201);
    }
}
