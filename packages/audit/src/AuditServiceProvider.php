<?php

declare(strict_types=1);

namespace Kern\Audit;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kern\Audit\Console\Commands\VerifyAuditChainsCommand;

/**
 * The audit chain's own wiring: its tables, its endpoints and its verifier travel with it.
 *
 * A package that needs a table and leaves the installing application to remember it is a package
 * that works until someone forgets. `loadMigrationsFrom` means requiring this is enough — the two
 * tables and the identity foreign keys arrive with the code that writes to them, and so do the two
 * read endpoints and the command that verifies every chain.
 *
 * Nothing is bound in the container. `AuditChainService` and `ChainVerifier` resolve themselves,
 * and an application that wants to swap one binds its own.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditChainsCommand::class]);
        }
    }

    /**
     * Mount the two read endpoints where `config('kern.routing')` says package routes are mounted.
     *
     * Spelled out here rather than borrowed from `Kern\Platform\Routing\PackageRoutes`, which does
     * exactly this: every other kern package requires the platform, and the platform requires *this*
     * one — every identity action and every settings change records an audit event. Reusing the
     * helper would close that into a cycle. The defaults are inline for the same reason: this
     * package has to work with nothing else installed.
     */
    private function loadRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(config()->array('kern.routing.middleware', ['web']))
            ->prefix(config()->string('kern.routing.prefix', 'api'))
            ->group(__DIR__ . '/../routes/api.php');
    }
}
