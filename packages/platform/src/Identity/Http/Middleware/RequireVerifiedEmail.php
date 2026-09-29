<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Services\EmailVerificationPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an account that has not proved it can read its own address at the verification screen.
 *
 * ## What it deliberately does not do
 *
 * It does not authenticate. A request with no user passes straight through, because refusing it here
 * would mean two middlewares answering the same question and disagreeing — `auth` owns that, and a
 * guest hitting a protected route should be told to sign in, not to verify an address it has not got.
 *
 * ## The allowed set is the whole safety of this middleware
 *
 * An unverified account can reach the verification screen, submit a code, ask for another, and sign
 * out. Nothing else. If that list is wrong in the other direction — if signing out or resending were
 * caught by it — a person would be held on a screen with no way forward and no way back, which is the
 * standard way this feature is got wrong. Matched on route *names*, not paths, so a prefix change
 * cannot silently strand somebody.
 *
 * ## Why the answer differs by what asked
 *
 * A browser navigation gets a redirect, because a person needs to arrive somewhere. A JSON request
 * gets 409 and a closed code: the screens fetch their own data, and a fetch that followed a redirect
 * into an HTML page would fail in a way no caller could read. 409 rather than 403 — nothing about
 * this request was forbidden, it is the account's state that is wrong, and it is the account's to fix.
 */
final readonly class RequireVerifiedEmail
{
    /** Reachable with an unverified address. Everything else is not. */
    private const array ALLOWED_ROUTES = [
        // The screen itself, and the request it makes to draw itself. Leaving the second one out is
        // the subtle version of this mistake: the page loads and then cannot say anything about why
        // the person is on it.
        'verify-email',
        'auth.email.status',
        'auth.email.verify',
        'auth.email.resend',
        // The way out. Without it, somebody whose address can no longer receive mail is held on a
        // screen they cannot act on and cannot leave.
        'auth.logout',
    ];

    public function __construct(private EmailVerificationPolicy $policy)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->email_verified_at !== null) {
            return $next($request);
        }

        if (! $this->policy->isRequired()) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if ($route !== null && in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['code' => 'EMAIL_NOT_VERIFIED'], Response::HTTP_CONFLICT);
        }

        return redirect()->route('verify-email');
    }
}
