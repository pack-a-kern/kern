<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->keepTestsOffSharedServices();
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

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
