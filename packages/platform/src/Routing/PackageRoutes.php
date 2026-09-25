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
        self::mount($file, ['kern.routing.middleware'], ['kern.routing.prefix']);
    }

    /**
     * The same, for routes that act inside one tenant.
     *
     * The extra segment and the middleware that binds the tenant from it are the application's
     * words — `v1/subjects/{subject}` and `tax-subject` here — so a package writes
     * `Route::get('documents', …)` and never learns what a taxpayer is.
     */
    public static function loadForTenant(string $file): void
    {
        self::mount(
            $file,
            // The application's group *and* the tenant's: the second says how a tenant is bound, and
            // it is not a replacement for the first. Mounting with the tenant middleware alone left
            // every one of these routes outside `web`, so no session was ever started and `auth:web`
            // answered 401 to a signed-in browser — while the test suite stayed green, because
            // `actingAs()` puts the user on the guard directly and never needs a session at all.
            ['kern.routing.middleware', 'kern.routing.tenant.middleware'],
            ['kern.routing.prefix', 'kern.routing.tenant.prefix'],
        );
    }

    /**
     * @param list<string> $middlewareKeys in order, concatenated
     * @param list<string> $prefixKeys     in order, joined with `/`
     */
    private static function mount(string $file, array $middlewareKeys, array $prefixKeys): void
    {
        if (app()->routesAreCached()) {
            return;
        }

        $middleware = [];

        foreach ($middlewareKeys as $key) {
            $middleware = [...$middleware, ...config()->array($key)];
        }

        $prefix = implode('/', array_map(
            static fn (string $key): string => config()->string($key),
            $prefixKeys,
        ));

        Route::middleware($middleware)->prefix($prefix)->group($file);
    }
}
