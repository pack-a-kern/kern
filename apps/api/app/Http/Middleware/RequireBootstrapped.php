<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kern\Legislation\Requirements\ReadinessReport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in person on the setup checklist until the install has the legislation its
 * required features rest on — ADR 0027 step 5, the global bootstrap gate.
 *
 * ## Why it lives here and not in a package
 *
 * It reads {@see ReadinessReport}, which is `kern/legislation`'s. Platform is the base layer and
 * cannot depend on legislation, so the one place that may both read the readiness query and know
 * the application's routes is `apps/api`, the composition root (ADR 0027, "the gate lives in the
 * app"). Registered in `bootstrap/app.php`'s `web(append:)` list, after `RequireVerifiedEmail`: an
 * address is proven before the install's readiness is ever consulted.
 *
 * ## What it deliberately does not do — it never walls the API
 *
 * A JSON request passes straight through. This is the property that keeps the gate from deadlocking
 * the very thing it waits for: the install is made ready by approving the minimum wage (on
 * `/taxes/rules`) and reading Cod Fiscal into the library (on `/legislation`), and both act through
 * `/api/v1/**`. A gate that answered those calls with a 409 until the wage was approved would make
 * the wage impossible to approve. So only browser navigation is held — the screens that cannot work
 * without fiscal data are the ones a person is kept away from, not the requests that create it.
 *
 * ## The allowed set is the whole safety of this middleware
 *
 * A person who is not bootstrapped can reach the checklist and the screens that satisfy it — the
 * legislation library and the tax-rules board — plus their own account and the way out. Everything
 * else redirects to the checklist. Matched on route *names*, not paths, so a prefix change cannot
 * silently strand somebody on a screen with no way to finish setting the install up (the mistake
 * {@see RequireVerifiedEmail} documents at length, for the same reason).
 *
 * Guests pass through: `auth` owns an unauthenticated request, and sends it to sign in rather than
 * to a checklist for an install it cannot yet see.
 */
final readonly class RequireBootstrapped
{
    /**
     * Reachable while the install is not yet bootstrapped.
     *
     * Everything needed to *finish* bootstrapping, and nothing that needs the install already to be.
     * `taxes.rules` approves the minimum wage; `legislation`/`legislation.law` read Cod Fiscal into
     * force; `setup` is where the gate sends people and must not redirect to itself; `account` and
     * `auth.logout` are the person's own, always theirs; `verify-email` is where `RequireVerifiedEmail`
     * sends an unproven address and must stay reachable through this gate too; `csp-report` is the
     * browser's own.
     *
     * @var list<string>
     */
    public const array ALLOWED_ROUTES = [
        'setup',
        'legislation',
        'legislation.law',
        'taxes.overview',
        'taxes.rules',
        'admin.settings',
        'admin.users',
        'account',
        'verify-email',
        'auth.logout',
        'csp-report',
    ];

    public function __construct(private ReadinessReport $readiness)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return $next($request);
        }

        // Never the API — see the class docblock: walling it would make the install impossible to
        // make ready. Only a browser navigation is held.
        if ($request->expectsJson()) {
            return $next($request);
        }

        $isBootstrapped = ($this->readiness)()['is_bootstrapped'] === true;
        $route = $request->route()?->getName();

        if ($isBootstrapped) {
            // Setup is done, so the checklist has nothing left on it. A person who lands there — a
            // bookmark, or the place the gate sent them just before the last piece went in — is sent
            // on to the dashboard, so the screen is never a dead end once it is finished.
            if ($route === 'setup') {
                return redirect()->route('dashboard');
            }

            return $next($request);
        }

        if ($route !== null && in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return redirect()->route('setup');
    }
}
