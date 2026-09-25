<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Http\Controllers\Auth\Concerns\EstablishesAuthenticatedSession;
use Kern\Platform\Identity\Actions\ConfirmTotpEnrollment;
use Kern\Platform\Identity\Actions\EnrollTotp;
use Kern\Platform\Identity\Exceptions\InvalidMfaCodeException;
use Kern\Platform\Identity\Exceptions\TotpAlreadyEnrolledException;
use Kern\Platform\Identity\Exceptions\TotpNotEnrolledException;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * `POST /api/v1/auth/mfa/totp/enroll` and `.../confirm` — behind `auth:web`. Confirming enrollment
 * is a privilege change (the account goes from "no MFA" to "MFA-protected"), so — like login — it
 * rotates the session id (`BLUEPRINT.md` §4.7's session-fixation requirement is not only about login).
 */
final class TotpController
{
    use EstablishesAuthenticatedSession;

    public function enroll(Request $request, EnrollTotp $enroll): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();

        try {
            $enrollment = $enroll($user);
        } catch (TotpAlreadyEnrolledException $exception) {
            throw ValidationException::withMessages(['totp' => [$exception->getMessage()]]);
        }

        return response()->json([
            'secret' => $enrollment->secret,
            'provisioning_uri' => $enrollment->provisioningUri,
        ]);
    }

    public function confirm(
        Request $request,
        ConfirmTotpEnrollment $confirm,
        SessionRegistrar $sessions,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();

        try {
            $codes = $confirm($user, $data['code']);
        } catch (InvalidMfaCodeException|TotpNotEnrolledException $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        // Confirming a second factor is a privilege change, so the session id rotates — and the
        // `user_sessions` row has to follow it, or the caller's own session becomes unmatchable.
        $this->rotateSessionFor($request, $user, $sessions);

        return response()->json(['recovery_codes' => $codes]);
    }
}
