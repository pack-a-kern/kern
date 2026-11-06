<?php

declare(strict_types=1);

namespace Tests\Feature\SharedPageDataTest;

use App\Http\Middleware\HandleInertiaRequests;
use Closure;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;

/**
 * What every page carries for the shell (`HandleInertiaRequests::share`) costs nothing on a call that
 * renders no page.
 *
 * `/api` runs through the `web` group, so Inertia builds the shared data for every JSON call too.
 * Built as values, that was the roles, the permissions, the taxpayer switcher and a key unwrapped for
 * every name in it — on each request. Closures are resolved only when a page is rendered; that pages
 * still receive them is pinned where they are read (`SystemHealthWatchTest`, `HomePageTest`).
 */
it('shares what costs a query as closures, so a JSON call never builds it', function (): void {
    $request = Request::create('/api/v1/subjects');
    $request->setUserResolver(static fn (): User => new User());

    $shared = app(HandleInertiaRequests::class)->share($request);

    foreach (['auth', 'currencies', 'systemHealth', 'documents'] as $key) {
        expect($shared[$key])->toBeInstanceOf(Closure::class);
    }
});
