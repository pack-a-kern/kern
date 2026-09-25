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
        'middleware' => ['web'],
    ],

];
