<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Http\Controllers\Auth\Concerns\EstablishesAuthenticatedSession;
use Kern\Platform\Identity\Actions\AttemptPasswordLogin;
use Kern\Platform\Identity\Exceptions\InvalidCredentialsException;
use Kern\Platform\Identity\Support\PendingMfaLogin;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * `POST /api/v1/auth/login` — step one of password login. If the account has a confirmed TOTP
 * method, this deliberately does **not** establish a session — it stashes a short-lived "password
 * verified, awaiting MFA" marker in the (still-anonymous) session and returns
 * `{"mfa_required": true}`; `Kern\Platform\Http\Controllers\Auth\MfaChallengeController` reads that
 * marker to finish the login. An account with no MFA enrolled is fully logged in by this single
 * step.
 *
 * Rate limited by two independent named limiters (`Kern\Platform\Identity\IdentityServiceProvider`)
 * attached in `routes/api.php` — see that provider's docblock for exactly how each is keyed.
 */
final class LoginController
{
    use EstablishesAuthenticatedSession;

    public function __invoke(
        Request $request,
        AttemptPasswordLogin $attempt,
        SessionRegistrar $sessions,
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $attempt($data['email'], $data['password']);
        } catch (InvalidCredentialsException $exception) {
            throw ValidationException::withMessages(['email' => [$exception->getMessage()]]);
        }

        if ($result->mfaRequired) {
            $request->session()->put(PendingMfaLogin::SESSION_KEY, [
                'user_id' => $result->user->id,
                'expires_at' => now('UTC')->addSeconds(config()->integer('identity.mfa.pending_ttl_seconds'))->timestamp,
            ]);

            return response()->json(['mfa_required' => true]);
        }

        $this->establishSessionFor($request, $result->user, $sessions);

        return response()->json(['mfa_required' => false, 'id' => $result->user->id]);
    }
}
