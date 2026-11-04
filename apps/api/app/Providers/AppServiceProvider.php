<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->keepTestsOffSharedServices();
    }

    public function boot(): void
    {
        $this->closeHorizonsDashboard();
        $this->keepScriptsAsBuilt();
    }

    /**
     * The application's scripts run as Vite wrote them, whatever a proxy in front would rather do.
     *
     * Cloudflare's Rocket Loader rewrites every `<script>` it passes — `type="module"` becomes a type of
     * its own — and runs them later from a script of its own. The page then preloads `app.js` and the
     * browser warns it went unused, the nonce the policy allows is no longer on the script that runs,
     * and a module bundle that already loads deferred gains nothing. `data-cfasync="false"` is
     * Cloudflare's documented way for a page to say "leave this one alone"; on any other host it is an
     * attribute nothing reads.
     */
    private function keepScriptsAsBuilt(): void
    {
        Vite::useScriptTagAttributes(['data-cfasync' => 'false']);
    }

    /**
     * Horizon's own dashboard (`/horizon`, and the 22 routes under it) is shut to everyone.
     *
     * The package always registers it, with no setting to leave it out, and lets in whoever its auth
     * callback allows. Its default allows everyone when `APP_ENV` is `local` — signed out included —
     * and nobody otherwise. `compose.dev.yaml` makes `local` the value whenever `.env` has none, so one
     * missing line put every job's payload, and a button that re-runs jobs, on a public address.
     *
     * Nothing here uses the dashboard: the Operations screen reads the same queues and failed jobs
     * itself, under this application's permissions (`Kern\TaxRomania\Operations`). So the answer is
     * no, for every request, in every environment. Horizon still runs the jobs; only its page is shut.
     */
    private function closeHorizonsDashboard(): void
    {
        Horizon::auth(static fn (): bool => false);
    }

    /**
     * The test suite never talks to Redis.
     *
     * `phpunit.xml` sets `CACHE_STORE=array`, `SESSION_DRIVER=array` and friends, but this
     * environment does not honour PHPUnit's `<env force="true">`: the container's own variables
     * win, and they point at Redis. A test then fails with `NOAUTH Authentication required`, which
     * says nothing about what the test was doing — and, worse, a suite that shares Redis with the
     * running application can evict its cache and its sessions.
     *
     * Done in `register()` rather than in the base TestCase because `RateLimiter` and Spatie's
     * `PermissionRegistrar` are singletons that capture their store when they are first resolved,
     * which happens while providers boot — anything set after that is too late for them.
     */
    private function keepTestsOffSharedServices(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        config([
            'cache.default' => 'array',
            'permission.cache.store' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'broadcasting.default' => 'null',
        ]);
    }
}
