<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication defaults
    |--------------------------------------------------------------------------
    |
    | Session-cookie auth ("web" guard), never token auth: this is a first-party
    | Inertia/React SPA (BLUEPRINT.md §1.3), not a public third-party API, so there
    | is no reason to accept the larger attack surface of long-lived bearer
    | tokens. `docs/handoff/017-authentication.md` documents the full login/MFA/
    | session lifecycle built on top of this guard.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication guards
    |--------------------------------------------------------------------------
    |
    | The "session" driver reads/writes `Illuminate\Contracts\Session\Session`
    | (config/session.php — Redis, BLUEPRINT.md §1.1). Kern\Platform\Identity's own
    | login/MFA/passkey flows populate this guard via `Auth::login()` only
    | after every required factor has passed; nothing in this app ever calls
    | `Auth::attempt()` directly (see docs/handoff/017-authentication.md "Key
    | decisions" for why the built-in attempt() flow is not used).
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User providers
    |--------------------------------------------------------------------------
    |
    | Kern\Platform\Identity\Models\User is the only Authenticatable model this
    | project has (BLUEPRINT.md §5.1) — a TaxSubject is never itself an
    | authentication principal, see docs/handoff/011-identity-tenancy.md.
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => Kern\Platform\Identity\Models\User::class,
        ],
    ],

];
