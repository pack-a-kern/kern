<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Account;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Actions\RevokeAllSessions;
use Kern\Platform\Identity\Models\User;

/**
 * `PUT /api/v1/me/password` — changes the signed-in user's password.
 *
 * The current password is required even though the session is already authenticated: a session
 * left open on a shared machine is exactly the case this protects against, and without the check
 * whoever finds it can lock the owner out permanently.
 *
 * Every other session is revoked; the one making the change stays signed in. The commonest reason
 * to change a password is suspecting someone else has it, and a change that leaves their session
 * working protects nothing in exactly that case. Signing in again on a phone is the price, and it
 * is paid with the new password. The revocation goes through `RevokeAllSessions`, so it reaches
 * the audit chain the same way the "sign out everywhere" control does.
 */
final class UpdatePasswordController
{
    public function __invoke(Request $request, AuditChainService $audit, RevokeAllSessions $revokeOthers): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // Length over composition: NIST SP 800-63B, and `uncompromised()` checks the value
            // against known breach corpora rather than asking for a symbol nobody remembers.
            'password' => ['required', 'confirmed', Password::min(12)->uncompromised()],
        ]);

        $current = (string) $user->password_hash;

        // The current-password check is a brute-force oracle without a leash: the secret does not
        // rotate, and the session asking — by the premise of requiring the password at all — may not
        // be the owner's. Budgeted per account and address like the step-up password gate, only failed
        // tries are counted, and proving the password forgives them, so a fumble never locks anyone out.
        $budget = $this->budget($request, $user);

        if (RateLimiter::tooManyAttempts($budget['key'], $budget['attempts'])) {
            // 429, the answer login and step-up already give and `lib/apiErrors.ts` translates. A 422
            // here would read as "that password is wrong", which it is not.
            throw new ThrottleRequestsException('Too many attempts.');
        }

        if ($current === '' || ! Hash::check((string) $validated['current_password'], $current)) {
            RateLimiter::hit($budget['key'], $budget['decay']);

            // Attributed to `current_password` so the form marks the right field, and phrased so it
            // never distinguishes "wrong password" from "no password set" (a passkey-only account).
            throw ValidationException::withMessages([
                'current_password' => [__('The current password is incorrect.')],
            ]);
        }

        RateLimiter::clear($budget['key']);

        $user->password_hash = Hash::make((string) $validated['password']);
        $user->save();

        $userKey = $user->getKey();

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: is_scalar($userKey) ? (string) $userKey : null,
            eventType: 'PASSWORD_CHANGED',
            resourceType: 'user',
            resourceId: is_scalar($userKey) ? (string) $userKey : '',
            // The hash of an empty payload: the event records *that* it happened and by whom.
            // Nothing about a password, old or new, belongs in an audit trail.
            payloadHash: $audit->hashPayload([]),
        ));

        $revoked = $revokeOthers($user, $request->session()->getId());

        return response()->json(['updated' => true, 'revoked_count' => $revoked]);
    }

    /**
     * The throttle budget for the current-password check, keyed per account and address the way every
     * other authenticated budget in this package is.
     *
     * @return array{key: string, attempts: int, decay: int}
     */
    private function budget(Request $request, User $user): array
    {
        $attempts = config('identity.rate_limits.change_password_per_user.max_attempts');
        $decay = config('identity.rate_limits.change_password_per_user.decay_seconds');

        return [
            'key' => 'change-password|' . $user->id . '|' . (string) $request->ip(),
            // Shipped defaults when the setting is missing or not an integer — falling back rather than
            // failing closed, so a mistyped value cannot lock an account out of changing its own password.
            'attempts' => is_int($attempts) && $attempts > 0 ? $attempts : 5,
            'decay' => is_int($decay) && $decay > 0 ? $decay : 300,
        ];
    }
}
