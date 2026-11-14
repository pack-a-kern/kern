<?php

declare(strict_types=1);

/*
 * `apps/browser-worker` — a browser this installation can send instructions to.
 *
 * Not an SPV concept, and not a legislation one. The worker drives whatever a playbook describes —
 * SPV's sign-in, the legislation portal's search — so its address and its client are the platform's
 * ({@see \Kern\Platform\BrowserWorker\BrowserWorkerClient}), and each package brings only its playbook.
 * This config was `spv.browser_worker`, then tax-romania's, until each of those stopped being true.
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

    /*
     * The CA its certificate is checked against, when that is a private one — the stack's own for the
     * bundled worker, which compose sets. Empty, the system's (Kern\Platform\Http\CertificateAuthority).
     */
    'ca_cert' => env('BROWSER_WORKER_CA_CERT'),

    /*
     * How long this installation waits on a single worker call.
     *
     * It has to outlast the slowest thing a playbook can ask for, or the application gives up on a run
     * that is still going and reports a worker that is working — which is a confusing way to describe a
     * slow website. ANAF's message list is served by a JSF application that takes tens of seconds to
     * render, and the playbook allows it 45; this allows for that plus the waiting and reading after it.
     * Read on every call, so a change on the settings screen reaches a queue worker already running.
     */
    'timeout_seconds' => (int) env('BROWSER_WORKER_TIMEOUT_SECONDS', 120),

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

    /*
     * A proxy the browsers go out through, for the playbooks named — each by its own `name`, comma
     * separated. Chained behind the worker's egress gate, never handed to the browser. Set on the
     * Browser card, where turning it off empties the address and the credentials, and turning it on
     * needs all three. A playbook that names its own proxy keeps it.
     *
     * No defaults: `enabled` is `true` or `false`, or every browser run fails naming it. The password
     * is the card's secret, stored enciphered; here only for an installation set by environment.
     */
    'proxy' => [
        'enabled' => env('BROWSER_WORKER_PROXY_ENABLED'),
        'server' => env('BROWSER_WORKER_PROXY_SERVER'),
        'username' => env('BROWSER_WORKER_PROXY_USERNAME'),
        'password' => env('BROWSER_WORKER_PROXY_PASSWORD'),
        'playbooks' => env('BROWSER_WORKER_PROXY_PLAYBOOKS'),
    ],
];
