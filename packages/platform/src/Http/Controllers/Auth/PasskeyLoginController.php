<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Http\Controllers\Auth\Concerns\EstablishesAuthenticatedSession;
use Kern\Platform\Identity\Actions\CompletePasskeyLogin;
use Kern\Platform\Identity\Support\SessionRegistrar;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\ChallengeExpiredException;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use Kern\Platform\Identity\Support\Webauthn\PasskeyManager;

/**
 * `POST /api/v1/auth/passkeys/login/options` and `.../login` — the fully passwordless login path.
 * Names no account: the browser offers the passkeys it keeps for this site, and the one chosen says
 * whose it is (`Kern\Platform\Identity\Support\Webauthn\PasskeyManager::requestOptions()` and
 * `verifyAssertion()`). Both routes are throttled per address (`passkey-ceremony-per-ip`).
 */
final class PasskeyLoginController
{
    use EstablishesAuthenticatedSession;

    public function options(Request $request, PasskeyManager $passkeys): JsonResponse
    {
        return response()->json($passkeys->requestOptions($this->ceremonyKey($request)));
    }

    public function store(
        Request $request,
        CompletePasskeyLogin $complete,
        SessionRegistrar $sessions,
    ): JsonResponse {
        $data = $request->validate([
            'credential' => ['required', 'array'],
        ]);

        try {
            $user = $complete($this->ceremonyKey($request), $data['credential'], $request->getHost());
        } catch (ChallengeExpiredException|PasskeyVerificationException $exception) {
            throw ValidationException::withMessages(['credential' => [$exception->getMessage()]]);
        }

        $this->establishSessionFor($request, $user, $sessions);

        return response()->json(['id' => $user->id]);
    }

    /**
     * Which pending challenge is this browser's: its session, before the login rotates it. Hashed,
     * so the cache never holds a usable session id.
     */
    private function ceremonyKey(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }
}
