<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Identity\Actions\ConfirmEmailVerification;
use Kern\Platform\Identity\Actions\RequestEmailVerification;
use Kern\Platform\Identity\Contracts\VerificationSender;
use Kern\Platform\Identity\Exceptions\InvalidVerificationCodeException;
use Kern\Platform\Identity\Exceptions\VerificationUndeliverableException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Services\EmailVerificationPolicy;

/**
 * The three things an account with an unverified address may ask for: where it stands, another code,
 * and to submit one.
 *
 * All three behind `auth:web`, and all three named in `RequireVerifiedEmail`'s allowed set — this is
 * the controller that middleware exists to keep reachable.
 *
 * ## `status` answers before a code is sent
 *
 * The screen has to be able to draw itself without asking for mail, or opening it twice would send
 * twice. So arriving is free, and asking costs a code.
 */
final class EmailVerificationController
{
    public function status(Request $request, EmailVerificationPolicy $policy, VerificationSender $sender): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'verified' => $user->email_verified_at !== null,
            'required' => $policy->isRequired(),
            'channel' => $sender->channel()->value,
            // Whether asking is even possible: an account with no address on file cannot be sent
            // anything, and the screen should say so rather than offer a button that fails.
            'deliverable' => $sender->canReach($user),
            /*
             * The address itself, so the screen can say where the code went.
             *
             * It is the signed-in account's own address and the screen exists to confirm precisely
             * it — "we sent a code" with no indication of where is the version of this screen people
             * get stuck on. Returned from here rather than read from the profile endpoint because that
             * one is behind this very gate.
             */
            'destination' => $sender->canReach($user) ? $user->email_ciphertext : null,
        ]);
    }

    public function resend(Request $request, RequestEmailVerification $request_verification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            // Not an error: a second tab, a back button, a refresh. Nothing is sent, and the answer is
            // the same one the screen would draw anyway.
            return response()->json(['sent' => false, 'verified' => true]);
        }

        try {
            $challenge = $request_verification($user);
        } catch (VerificationUndeliverableException) {
            return response()->json(['code' => 'VERIFICATION_UNDELIVERABLE'], 422);
        }

        return response()->json([
            'sent' => true,
            'expiresAt' => $challenge->expires_at->toIso8601String(),
        ]);
    }

    /** @throws ValidationException */
    public function verify(Request $request, ConfirmEmailVerification $confirm): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            // A string, not an integer: a code with a leading zero is not the number it looks like,
            // and `'012345'` cast to int is a different code entirely.
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ]);

        if ($user->email_verified_at !== null) {
            return response()->json(['verified' => true]);
        }

        try {
            $confirm($user, (string) $validated['code']);
        } catch (InvalidVerificationCodeException) {
            // Through the validator so the screen shows it against the field the person typed in,
            // rather than as a banner they have to connect to it themselves.
            throw ValidationException::withMessages(['code' => __('The verification code is not valid.')]);
        }

        return response()->json(['verified' => true]);
    }
}
