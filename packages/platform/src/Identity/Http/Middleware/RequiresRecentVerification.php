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
 * ## One refusal, and it is always answerable
 *
 * `VERIFICATION_REQUIRED` is only ever sent to an account that holds a factor this can challenge, so
 * the dialog it raises can always be satisfied. There is no second code for "enrol first", because an
 * account with nothing enrolled is not stopped here at all.
 *
 * 403 carrying `reason` — the envelope rendered centrally and parsed by `resources/js/lib/http.ts`,
 * so a screen acts on the code rather than matching on prose.
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

        // An account that has not turned on two-factor authentication is not held up here.
        //
        // This is the installation owner's choice to make, and it is the choice they already made on
        // the security screen: a gate that demanded enrolment at the moment somebody tried to do
        // something would be forcing a decision at the worst possible time, in a dialog, to get past
        // a screen. The account's own settings are where that belongs.
        //
        // The consequence is stated plainly rather than hidden: a password alone reaches these routes
        // on an account with no second factor, exactly as it reaches everything else that account can
        // see. Turning TOTP on is what makes this gate exist, and then it is enforced properly rather
        // than merely recorded — which is the half that was broken before.
        if (! $this->policy->canVerify($user)) {
            return $next($request);
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
