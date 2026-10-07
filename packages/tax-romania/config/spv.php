<?php

declare(strict_types=1);

// Milestone 10 SPV automation — BLUEPRINT.md §13, §6.11, docs/reference/anaf-spv-integration.md.
return [


    // Engineering default, not a blueprint value — BLUEPRINT.md §3.4 requires an OTP/safety-code
    // TTL but names no number (the same gap docs/handoff/004's Open question #2 records for the
    // browser session TTL itself). A code older than this is never forwarded to the worker; the
    // session is failed and destroyed instead. Needs a deliberate decision before production,
    // not a silent carry-forward — see this milestone's handoff note.
    /*
     * The timezone the portal's own screens render dates in.
     *
     * ANAF prints "15.09.2026 20:49:40" with no offset, and reading that as UTC puts every message two
     * or three hours late — harmless until a document shown late on 31 December lands in the following
     * tax year, which is the kind of silent error the provenance chain exists to prevent.
     *
     * Configurable because it is a statement about a remote system that could be wrong, not because an
     * installation would want a different answer.
     */
    'portal_timezone' => env('SPV_PORTAL_TIMEZONE', 'Europe/Bucharest'),

    'challenge_ttl_seconds' => (int) env('SPV_CHALLENGE_TTL_SECONDS', 300),

    // ANAF's documented certificate-authenticated web service
    // (docs/reference/anaf-spv-integration.md — verified 2026-09-08, not inferred). Only
    // `listaMesaje`/`descarcare` are documented; there is no documented D212 submission endpoint.
    'anaf_api' => [
        'base_url' => env('ANAF_SPV_WEBSERVICE_BASE_URL', 'https://webserviced.anaf.ro/SPVWS2/rest'),
        'timeout_seconds' => (int) env('ANAF_SPV_WEBSERVICE_TIMEOUT_SECONDS', 30),

        // `listaMesaje?zile=<days>` lookback window — an engineering default (the documented
        // service takes this as a caller-supplied parameter; ANAF's own text names no default).
        'messages_window_days' => (int) env('ANAF_SPV_WEBSERVICE_MESSAGES_WINDOW_DAYS', 60),

        // A qualified digital certificate is the only documented auth method for this API. No
        // default: an unset path means this transport is unusable and must fail closed rather
        // than attempt an unauthenticated call (docs/AGENTS.md rule 5 — never a credential in
        // plaintext application state; the certificate + its password are filesystem/KMS
        // concerns of the deployment, not values this codebase generates or stores).
        'client_certificate_path' => env('ANAF_SPV_CLIENT_CERTIFICATE_PATH'),
        'client_certificate_password' => env('ANAF_SPV_CLIENT_CERTIFICATE_PASSWORD'),
    ],
];
