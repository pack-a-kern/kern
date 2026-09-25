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
        'middleware' => ['web'],

        'tenant' => [
            'prefix' => 'v1/subjects/{subject}',
            'middleware' => ['auth:web', 'tax-subject'],
        ],
    ],

];
