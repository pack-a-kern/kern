<?php

declare(strict_types=1);

/*
 * `apps/browser-worker` — a browser this installation can send instructions to.
 *
 * Not an SPV concept. The worker drives whatever a playbook describes, and SPV is the first thing
 * described to it; this config was `spv.browser_worker` until that stopped being true.
 *
 * Never reachable by the browser or the PWA (`BLUEPRINT.md` §13.4): it sits on a network with the
 * application and nothing else, and nothing is published to the host.
 */
return [
    /*
     * Where the worker is.
     *
     * No default. Where a service lives is the installation's fact, not this package's: a hostname
     * written here would be one repository's compose file baked into a package installed into
     * applications it will never see. The example beside this config carries the value, and
     * `kern:env` puts it into every installation's own file.
     */
    'url' => env('BROWSER_WORKER_URL'),

    'timeout_seconds' => (int) env('BROWSER_WORKER_TIMEOUT_SECONDS', 30),

    /*
     * The shared secret, sent as `Authorization: Bearer <token>`, which must equal the worker's own
     * `BROWSER_AUTH_TOKEN`.
     *
     * Empty by default, and empty means *no header* rather than a refusal: the worker decides
     * whether it wants one, through its own `BROWSER_AUTH_MODE`. An installation whose worker is
     * reachable only by this application can leave both unset; one that wants the second lock sets
     * both. Refusing here on the worker's behalf is what the transport this replaced did, and it
     * made a deployment choice look like a fault.
     *
     * Never logged (`BLUEPRINT.md` §4.10).
     */
    'auth_token' => env('BROWSER_WORKER_TOKEN', ''),
];
