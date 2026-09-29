<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Kern
|--------------------------------------------------------------------------
|
| Where the `kern/*` packages mount the routes they ship, in this application's words.
|
| The whole `routing` block is restated rather than partly overridden: a package's config is merged
| one level deep, so naming `tenant` alone would replace the block and leave the API with no prefix
| at all. `tax-subject` is the alias `bootstrap/app.php` gives
| `App\Http\Middleware\BindTaxSubjectContext`, which is what turns `{subject}` into a bound tenant.
*/

return [

    'routing' => [
        'prefix' => env('KERN_ROUTE_PREFIX', 'api'),
        /*
         * `verified` here rather than on each authenticated route: the gate has to cover every JSON
         * endpoint every package ships, and a list maintained per route is a list with a hole in it.
         * It passes a guest straight through, so signing in and registering are unaffected, and it
         * allows the verification endpoints by name — see
         * `Kern\Platform\Identity\Http\Middleware\RequireVerifiedEmail`.
         *
         * This file is the *published* copy and it wins over the package's own default, which is why
         * the same line exists in both: adding it only to the package's `config/kern.php` left the gate
         * applied to no route at all, and the test that asked a closed endpoint for data got a 200.
         */
        'middleware' => ['web', 'verified'],

        'tenant' => [
            'prefix' => 'v1/subjects/{subject}',
            'middleware' => ['auth:web', 'tax-subject', 'verified'],
        ],
    ],

];
