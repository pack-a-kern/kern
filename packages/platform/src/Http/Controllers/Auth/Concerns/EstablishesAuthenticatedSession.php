<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Exceptions\AccountSuspendedException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SessionRegistrar;

/**
 * The one place every "login just fully succeeded" controller action goes: rotate the session id
 * (`BLUEPRINT.md` §4.7 "session fixation... the session id must change on login"), bind the `Auth`
 * guard, then record the new session in `user_sessions`
 * (`Kern\Platform\Identity\Support\SessionRegistrar`) so device/session listing sees it immediately.
 *
 * Used identically by the three ways a login can finish: password-only (no MFA enrolled),
 * password + MFA challenge, and passkey assertion — extracted here once they were the same three
 * lines in three controllers (`docs/CODE-QUALITY.md` §1, stable duplicated knowledge, not
 * incidental similarity).
 */
trait EstablishesAuthenticatedSession
{
    private function establishSessionFor(Request $request, User $user, SessionRegistrar $sessions): void
    {
        // Checked here as well as in `AttemptPasswordLogin`, because this is the one door all three
        // logins share: the MFA step and a passkey reach it without passing through the password check.
        if ($user->status !== UserStatus::ACTIVE) {
            throw AccountSuspendedException::create();
        }

        Auth::login($user);
        $request->session()->regenerate();

        $sessionId = $request->session()->getId();

        $sessions->register($user->id, $sessionId, $request->ip(), $request->userAgent());
    }

    /**
     * Regenerates the session id *and* moves the `user_sessions` row with it.
     *
     * Any code that rotates the id for an already-authenticated user must go through here.
     * `$request->session()->regenerate()` on its own leaves the ledger pointing at an id that no
     * longer exists — see `SessionRegistrar::rotate()` for what that breaks.
     */
    private function rotateSessionFor(Request $request, User $user, SessionRegistrar $sessions): void
    {
        $previousId = $request->session()->getId();

        $request->session()->regenerate();

        $sessions->rotate(
            $previousId,
            $request->session()->getId(),
            $user->id,
            $request->ip(),
            $request->userAgent(),
        );
    }
}
