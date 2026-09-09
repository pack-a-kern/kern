<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\AttemptPasswordLogin;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\Support\SessionRegistrar;
use App\Http\Controllers\Api\V1\Auth\Concerns\EstablishesAuthenticatedSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/auth/login` — step one of password login. If the account has a confirmed TOTP
 * method, this deliberately does **not** establish a session — it stashes a short-lived "password
 * verified, awaiting MFA" marker in the (still-anonymous) session and returns
 * `{"mfa_required": true}`; `App\Http\Controllers\Api\V1\Auth\MfaChallengeController` reads that
 * marker to finish the login. An account with no MFA enrolled is fully logged in by this single
 * step.
 *
 * Rate limited by two independent named limiters (`App\Domain\Identity\IdentityServiceProvider`)
 * attached in `routes/api.php` — see that provider's docblock for exactly how each is keyed.
 */
final class LoginController extends Controller
{
    use EstablishesAuthenticatedSession;

    private const string PENDING_MFA_SESSION_KEY = 'auth.mfa_pending';

    private const int PENDING_MFA_TTL_SECONDS = 300;

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
            $request->session()->put(self::PENDING_MFA_SESSION_KEY, [
                'user_id' => $result->user->id,
                'expires_at' => now('UTC')->addSeconds(self::PENDING_MFA_TTL_SECONDS)->timestamp,
            ]);

            return response()->json(['mfa_required' => true]);
        }

        $this->establishSessionFor($request, $result->user, $sessions);

        return response()->json(['mfa_required' => false, 'id' => $result->user->id]);
    }
}
