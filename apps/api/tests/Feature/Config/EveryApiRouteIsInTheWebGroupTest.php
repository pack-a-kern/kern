<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every `api/` route runs inside the `web` middleware group.
 *
 * This application authenticates with a session cookie rather than a token (`config/auth.php`,
 * `bootstrap/app.php`), so a route outside `web` never starts a session, never reads the cookie, and
 * answers 401 to a signed-in browser. There is nothing to see in a log, and — the reason this test
 * exists — nothing to see in the rest of the suite either: `actingAs()` puts the user straight onto
 * the guard, so a feature test passes whether or not a session could ever have been established.
 *
 * That is exactly how `kern/documents`' routes shipped mounted under `['auth:web', 'tax-subject']`
 * alone, with the tenant middleware replacing the group instead of adding to it. Every document
 * endpoint answered 401 in the browser while 901 tests stayed green.
 */
it('mounts every api route inside the web group, whichever package registered it', function (): void {
    $outside = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        if (! in_array('web', $route->gatherMiddleware(), true)) {
            $outside[] = implode('|', $route->methods()) . ' ' . $route->uri();
        }
    }

    expect($outside)->toBe([]);
});

it('has api routes to check in the first place', function (): void {
    // A filter that stopped matching would make the assertion above pass by examining nothing.
    $api = array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/'),
    );

    expect(count($api))->toBeGreaterThan(100);
});
