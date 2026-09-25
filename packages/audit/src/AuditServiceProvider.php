<?php

declare(strict_types=1);

namespace Kern\Audit;

use Illuminate\Support\ServiceProvider;

/**
 * The audit chain's own wiring: its tables travel with it.
 *
 * A package that needs a table and leaves the installing application to remember it is a package
 * that works until someone forgets. `loadMigrationsFrom` means requiring this is enough — the two
 * tables and the identity foreign keys arrive with the code that writes to them.
 *
 * Nothing is bound in the container. `AuditChainService` and `ChainVerifier` resolve themselves,
 * and an application that wants to swap one binds its own.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
