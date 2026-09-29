<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Kern
|--------------------------------------------------------------------------
|
| Shared by every `kern/*` package rather than owned by one of them, which is why the key is `kern`
| and not `platform`: `kern/audit` reads it too, and it must not have to require the platform to
| learn where its own two endpoints live.
*/

return [

    /*
     * Where a package's JSON routes are mounted, and what wraps them.
     *
     * Every kern package loads its own route files — a package whose endpoints the application had
     * to register would be a package that is half installed. What it cannot decide is the prefix
     * they hang from or the middleware group they run in, because that is a property of the
     * application: this one authenticates with a session cookie, so its API lives inside `web`
     * rather than in Laravel's stateless `api` group (see `bootstrap/app.php`).
     */
    'routing' => [
        'prefix' => env('KERN_ROUTE_PREFIX', 'api'),
        /*
         * `verified` here rather than on each authenticated route: the gate has to cover every JSON
         * endpoint a package ships, and a list that has to be remembered per route is a list with a
         * hole in it. It passes a guest straight through — the sign-in and registration endpoints in
         * this same group are unaffected — and it allows the verification endpoints themselves by
         * name. See `Kern\Platform\Identity\Http\Middleware\RequireVerifiedEmail`.
         */
        'middleware' => ['web', 'verified'],

        /*
         * And where the ones that act *inside* a tenant hang from.
         *
         * A package that handles a tenant's documents has to put its routes under something that
         * names the tenant, and it must not be the one to decide what that is called: here a tenant
         * is a taxpayer and the segment reads `v1/subjects/{subject}`, elsewhere it is a workspace.
         * The middleware named second is what turns that segment into a bound tenant — the
         * application's, because authorising it is the application's answer (see `TenantGate`).
         */
        'tenant' => [
            'prefix' => 'v1/tenants/{tenant}',
            'middleware' => ['auth:web', 'tenant', 'verified'],
        ],
    ],

];
