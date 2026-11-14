<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-origin requests
|--------------------------------------------------------------------------
|
| None. The pages call the API from the origin that served them, and the services behind it call each
| other server to server, where a browser's cross-origin rules do not apply. Laravel's own default
| answers `Access-Control-Allow-Origin: *` on `api/*` — harmless while the API is read only with a
| session cookie, which a browser never sends to a wildcard, and a cross-tenant leak the day anyone
| turns credentials on. With no path listed, no request is answered with any CORS header at all.
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
