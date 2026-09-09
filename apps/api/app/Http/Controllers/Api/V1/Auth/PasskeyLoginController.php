<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\CompletePasskeyLogin;
use App\Domain\Identity\Support\SessionRegistrar;
use App\Domain\Identity\Support\Webauthn\Exceptions\ChallengeExpiredException;
use App\Domain\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use App\Domain\Identity\Support\Webauthn\PasskeyManager;
use App\Http\Controllers\Api\V1\Auth\Concerns\EstablishesAuthenticatedSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/auth/passkeys/login/options` and `.../login` — the fully passwordless login path.
 * Identifies the account by email first (never a discoverable/resident-key flow — see
 * `App\Domain\Identity\Support\Webauthn\PasskeyManager::requestOptionsForEmail()`'s docblock for
 * why, including the enumeration-resistance argument for `options()` below).
 */
final class PasskeyLoginController extends Controller
{
    use EstablishesAuthenticatedSession;

    public function options(Request $request, PasskeyManager $passkeys): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        return response()->json($passkeys->requestOptionsForEmail($data['email']));
    }

    public function store(
        Request $request,
        CompletePasskeyLogin $complete,
        SessionRegistrar $sessions,
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'credential' => ['required', 'array'],
        ]);

        try {
            $user = $complete($data['email'], $data['credential'], $request->getHost());
        } catch (ChallengeExpiredException|PasskeyVerificationException $exception) {
            throw ValidationException::withMessages(['credential' => [$exception->getMessage()]]);
        }

        $this->establishSessionFor($request, $user, $sessions);

        return response()->json(['id' => $user->id]);
    }
}
