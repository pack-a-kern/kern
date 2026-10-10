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
 * ## A refusal is always answerable
 *
 * The refusal names the factor to ask for — `VERIFICATION_REQUIRED` for a code,
 * `PASSWORD_CONFIRMATION_REQUIRED` for a password — and is only ever sent to an account that holds
 * the one it names, so the dialog it raises can always be satisfied. There is no code for "enrol
 * first", because an account holding nothing to ask for is not stopped here at all.
 *
 * It was the code alone once, which meant the gate applied to the accounts that had armed themselves
 * with an authenticator app and to no others — the ones most in need of it were the ones exempt. A
 * password is a weaker proof than a code and a far stronger one than the cookie that is already in
 * hand, which is the whole of what this guards against.
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

        /*
         * The free question first.
         *
         * A session already inside the window passes whatever factor the account holds, so asking
         * which one costs a query whose answer cannot change the outcome. Reading the session is a
         * key lookup in a payload this request has already loaded; `challengeFor()` is a trip to the
         * database. Ordering them the other way round — which is how this was written — charged every
         * request in a verified window for an answer it then ignored, and the SPV screen spends most
         * of its life inside one.
         */
        if ($this->policy->isRecentlyVerified($request->session())) {
            return $next($request);
        }

        $factor = $this->policy->challengeFor($user);

        // Nothing this account can be asked for, so nothing to hold it up with. Today that is only a
        // passkey-only account; `StepUpFactor` carries the reasoning and the consequence.
        if ($factor === null) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This action needs you to confirm who you are.',
            'reason' => $factor->refusalReason(),
        ], Response::HTTP_FORBIDDEN);
    }
}
