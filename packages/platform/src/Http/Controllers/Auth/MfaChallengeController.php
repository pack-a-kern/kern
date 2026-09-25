<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Http\Controllers\Auth\Concerns\EstablishesAuthenticatedSession;
use Kern\Platform\Identity\Actions\CompleteMfaChallenge;
use Kern\Platform\Identity\Exceptions\InvalidMfaCodeException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * `POST /api/v1/auth/mfa/challenge` — step two of a login that `Kern\Platform\Http\Controllers\Auth\
 * LoginController` flagged `mfa_required`. Reads the pending marker that controller stashed in the
 * (still-anonymous) session; there is no other way to reach this endpoint's success path than
 * having just passed step one for the same session.
 */
final class MfaChallengeController
{
    use EstablishesAuthenticatedSession;

    private const string PENDING_MFA_SESSION_KEY = 'auth.mfa_pending';

    public function __invoke(
        Request $request,
        CompleteMfaChallenge $complete,
        SessionRegistrar $sessions,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        /** @var array{user_id: string, expires_at: int}|null $pending */
        $pending = $request->session()->get(self::PENDING_MFA_SESSION_KEY);

        if ($pending === null || $pending['expires_at'] < now('UTC')->timestamp) {
            $request->session()->forget(self::PENDING_MFA_SESSION_KEY);

            throw ValidationException::withMessages(['code' => ['No pending login to complete. Please sign in again.']]);
        }

        $user = User::query()->findOrFail($pending['user_id']);

        try {
            $complete($user, $data['code']);
        } catch (InvalidMfaCodeException $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        $request->session()->forget(self::PENDING_MFA_SESSION_KEY);
        $this->establishSessionFor($request, $user, $sessions);

        return response()->json(['id' => $user->id]);
    }
}
