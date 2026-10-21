<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Exceptions\AccountSuspendedException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\SessionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that is no longer `ACTIVE`, on the first request it makes.
 *
 * Suspending through the admin screen already revokes every session, so in the ordinary case this never
 * fires. It exists for every other way a status can change — a direct database edit, an erasure, a future
 * code path that forgets to revoke — because the guarantee "a suspended account cannot act" must not
 * depend on each writer of the column remembering to sign the account out.
 *
 * Appended to the whole `web` group rather than aliased onto routes: a lock that a route could leave off
 * is not a lock. A request with no user passes straight through — `auth` owns that question.
 *
 * The user is read fresh from the guard on each request, so the status is the database's, not one cached
 * in the session from when the person signed in.
 */
final readonly class EndInactiveAccountSession
{
    public function __construct(private SessionRegistrar $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status === UserStatus::ACTIVE) {
            return $next($request);
        }

        $this->sessions->revoke($request->session()->getId());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw AccountSuspendedException::create();
    }
}
