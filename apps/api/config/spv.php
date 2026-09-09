<?php

declare(strict_types=1);

// Milestone 10 SPV automation — BLUEPRINT.md §13, §6.11, docs/reference/anaf-spv-integration.md.
return [

    // The apps/spv-browser worker (docs/handoff/004-python-browser-services.md's session
    // lifecycle, extended by this milestone). Never reachable directly by the browser/PWA —
    // BLUEPRINT.md §13.4.
    'browser_worker' => [
        'base_url' => env('SPV_BROWSER_BASE_URL', 'http://spv-browser:8090'),
        'timeout_seconds' => (int) env('SPV_BROWSER_TIMEOUT_SECONDS', 30),

        // Shared secret sent as `Authorization: Bearer <token>` — must equal the worker's own
        // `SPV_BROWSER_AUTH_TOKEN`. Empty by default so a missing value fails closed
        // (SpvWorkerUnavailableException::authenticationNotConfigured()), matching
        // App\Domain\Documents\Extraction\HttpTextExtractionGateway's identical precedent.
        // Never logged (BLUEPRINT.md §4.10).
        'auth_token' => env('SPV_BROWSER_AUTH_TOKEN', ''),
    ],

    // Engineering default, not a blueprint value — BLUEPRINT.md §3.4 requires an OTP/safety-code
    // TTL but names no number (the same gap docs/handoff/004's Open question #2 records for the
    // browser session TTL itself). A code older than this is never forwarded to the worker; the
    // session is failed and destroyed instead. Needs a deliberate decision before production,
    // not a silent carry-forward — see this milestone's handoff note.
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
