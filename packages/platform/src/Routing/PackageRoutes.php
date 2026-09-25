<?php

declare(strict_types=1);

namespace Kern\Platform\Routing;

use Illuminate\Support\Facades\Route;

/**
 * Mounts a package's own route file where the application says packages are mounted.
 *
 * A package that shipped no routes would be half installed — the application would have to know
 * every endpoint of every module it required, which is the filing problem this split exists to end.
 * But a package cannot decide the prefix it hangs from or the middleware group it runs in: that is
 * the application's shape, and here it is `api` inside `web`, because authentication is a session
 * cookie rather than a token (`bootstrap/app.php`). So the package brings the routes and
 * `config('kern.routing')` brings the mounting.
 *
 * Routes are skipped when they are already cached: `route:cache` has compiled them once, and loading
 * the file again would register every route twice.
 */
final class PackageRoutes
{
    public static function load(string $file): void
    {
        if (app()->routesAreCached()) {
            return;
        }

        /** @var array<int, string> $middleware */
        $middleware = config()->array('kern.routing.middleware');

        Route::middleware($middleware)
            ->prefix(config()->string('kern.routing.prefix'))
            ->group($file);
    }
}
