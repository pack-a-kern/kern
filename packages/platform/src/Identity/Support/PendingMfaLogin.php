<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support;

use Illuminate\Http\Request;

/**
 * A password login that still owes its second factor.
 *
 * `LoginController` writes it into the still-anonymous session when the password was right and a code
 * is due; `MfaChallengeController` reads it back to finish the login. The MFA throttle reads it too:
 * before the code, there is no signed-in user to count guesses against, and counting them per address
 * gave anyone holding the password five guesses per address per minute — as many as they had
 * addresses. The account the login is for is the one budget that cannot be multiplied.
 */
final class PendingMfaLogin
{
    public const string SESSION_KEY = 'auth.mfa_pending';

    /** The account a live pending login is for, or null when there is none or it has expired. */
    public static function userId(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($pending['user_id'] ?? null) || ! is_int($pending['expires_at'] ?? null)) {
            return null;
        }

        return $pending['expires_at'] >= now('UTC')->timestamp ? $pending['user_id'] : null;
    }
}
