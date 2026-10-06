<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\StepUpPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a sensitive act until this session has proved, recently, that the person at the keyboard
 * holds the account's second factor.
 *
 * Marked on the routes that need it — `routes/*.php`, alias `verify-recent` — rather than configured
 * at runtime. A switch that *unprotects* a route is flipped once during development and never flipped
 * back, and a reviewer reading the route file sees nothing; the window is a setting, the list is not.
 *
 * ## What it deliberately does not do
 *
 * It does not authenticate. A request with no user passes through to `auth`, which owns that answer
 * and should not be contradicted here — a guest must be told to sign in, not to re-verify an account
 * it has not got.
 *
 * ## Two refusals, not one
 *
 * `MFA_ENROLLMENT_REQUIRED` means there is nothing to ask for: the account holds no factor this can
 * challenge, and the person must enrol one first. `VERIFICATION_REQUIRED` means the factor exists and
 * the dialog can proceed. Collapsing them would send somebody with no TOTP method to a dialog that
 * could never succeed, which is the standard way this feature strands people.
 *
 * Both are 403 and both carry `reason`, the envelope `Kern\Platform\...` renders centrally and
 * `resources/js/lib/http.ts` parses, so a screen can act on the code rather than match on prose.
 *
 * ## Why no redirect for a browser navigation
 *
 * Unlike `RequireVerifiedEmail`, every route this guards is a JSON endpoint called by the SPA, never
 * a page a person navigates to. There is nowhere to redirect to and nothing to render: the client
 * catches the code, raises the dialog over whatever screen is already open, and retries. A page route
 * that ever needs this would need its own answer, and should not borrow this one silently.
 */
final readonly class RequiresRecentVerification
{
    public function __construct(private StepUpPolicy $policy)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $this->policy->canVerify($user)) {
            return response()->json([
                'message' => 'This account must enroll an authenticator app before performing this action.',
                'reason' => 'MFA_ENROLLMENT_REQUIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $this->policy->isRecentlyVerified($request->session())) {
            return response()->json([
                'message' => 'This action needs your authenticator code.',
                'reason' => 'VERIFICATION_REQUIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
