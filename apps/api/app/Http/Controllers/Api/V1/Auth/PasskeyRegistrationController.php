<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\CompletePasskeyRegistration;
use App\Domain\Identity\Support\Webauthn\Exceptions\ChallengeExpiredException;
use App\Domain\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use App\Domain\Identity\Support\Webauthn\PasskeyManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/auth/passkeys/registration/options` and `.../registration` — behind the `auth:web`
 * guard (registering a *new* passkey requires an already-authenticated session; there is no
 * "register a passkey for an account you have not proven you control some other way" path).
 */
final class PasskeyRegistrationController extends Controller
{
    public function options(Request $request, PasskeyManager $passkeys): JsonResponse
    {
        /** @var \App\Domain\Identity\Models\User $user */
        $user = $request->user();

        return response()->json($passkeys->creationOptionsFor($user));
    }

    public function store(Request $request, PasskeyManager $passkeys, CompletePasskeyRegistration $complete): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'array'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var \App\Domain\Identity\Models\User $user */
        $user = $request->user();

        try {
            $passkey = $complete($user, $data['credential'], $request->getHost(), $data['name'] ?? null);
        } catch (ChallengeExpiredException|PasskeyVerificationException $exception) {
            throw ValidationException::withMessages(['credential' => [$exception->getMessage()]]);
        }

        return response()->json(['id' => $passkey->id], 201);
    }
}
