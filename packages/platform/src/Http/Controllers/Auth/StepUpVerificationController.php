<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Identity\Actions\CompleteMfaChallenge;
use Kern\Platform\Identity\Exceptions\InvalidMfaCodeException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\StepUpPolicy;

/**
 * `POST /api/v1/auth/step-up` — proves, inside an existing session, that the person at the keyboard
 * still holds the account's second factor, so that
 * `Kern\Platform\Identity\Http\Middleware\RequiresRecentVerification` will let a sensitive act
 * through for the length of the window.
 *
 * ## Why this reuses the login challenge wholesale
 *
 * `CompleteMfaChallenge` already verifies a TOTP code against the enrolled secret, falls back to the
 * account's unused recovery codes, marks a matched recovery code used in the same request so a replay
 * fails, and records the outcome. Every one of those properties is wanted here unchanged. A second
 * implementation of "is this code right" is the kind of duplication that drifts apart until one of
 * the two has a weaker answer.
 *
 * The difference between the two endpoints is not the verification — it is what a success grants. There
 * it establishes a session; here it stamps one that already exists.
 *
 * ## Failures are a validation error, not a 403
 *
 * A wrong code is something the person can fix by typing again, and the dialog shows it against the
 * field. A 403 would be read by the client's own interceptor as "you must verify", which is what it
 * was already doing — so answering a bad code that way would loop.
 */
final class StepUpVerificationController
{
    public function __invoke(
        Request $request,
        CompleteMfaChallenge $complete,
        StepUpPolicy $policy,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // The middleware says this before the dialog ever opens, but the endpoint is reachable on its
        // own and must not pretend to have verified an account that holds nothing to verify.
        if (! $policy->canVerify($user)) {
            throw ValidationException::withMessages([
                'code' => ['This account has no authenticator app enrolled.'],
            ]);
        }

        try {
            $complete($user, $data['code']);
        } catch (InvalidMfaCodeException $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        $policy->markVerified($request->session());

        return response()->json(['verified_for_seconds' => $policy->windowSeconds()]);
    }
}
