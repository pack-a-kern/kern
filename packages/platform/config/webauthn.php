<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | WebAuthn relying party
    |--------------------------------------------------------------------------
    |
    | `id` MUST be a registrable domain suffix of every origin passkeys are
    | used from (the WebAuthn spec's "RP ID" — see
    | Webauthn\CeremonyStep\CheckRelyingPartyIdIdHash). Defaulting it to
    | APP_URL's host keeps dev/staging/prod correct without a second place to
    | edit when APP_URL changes; override with WEBAUTHN_RP_ID only if the
    | public-facing domain genuinely differs from APP_URL (e.g. behind a
    | reverse proxy rewriting Host).
    |
    */

    'relying_party' => [
        'name' => env('APP_NAME'),
        'id' => env('WEBAUTHN_RP_ID', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed origins
    |--------------------------------------------------------------------------
    |
    | Webauthn\CeremonyStep\CheckAllowedOrigins rejects any ceremony whose
    | browser-reported origin is not in this list — this is the origin-binding
    | half of phishing resistance (a cloned login page on a different origin
    | cannot replay a ceremony against a real relying party). Comma-separated;
    | defaults to APP_URL alone.
    |
    */

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBAUTHN_ALLOWED_ORIGINS', (string) env('APP_URL', 'http://localhost'))),
    )),

    /*
    |--------------------------------------------------------------------------
    | Ceremony timeout
    |--------------------------------------------------------------------------
    |
    | Milliseconds, sent to the browser as PublicKeyCredentialCreationOptions/
    | PublicKeyCredentialRequestOptions.timeout. Purely a UX hint to the
    | authenticator UI — the server-side challenge itself expires via the
    | signed-challenge TTL in Kern\Platform\Identity\Support\Webauthn\
    | ChallengeStore, not this value.
    |
    */

    'timeout_ms' => (int) env('WEBAUTHN_TIMEOUT_MS', 60000),

    /*
    |--------------------------------------------------------------------------
    | Challenge TTL
    |--------------------------------------------------------------------------
    |
    | How long a generated registration/assertion challenge remains acceptable
    | (seconds). Stored server-side (cache, see ChallengeStore) keyed to the
    | ceremony, never trusted from client input.
    |
    */

    'challenge_ttl_seconds' => (int) env('WEBAUTHN_CHALLENGE_TTL_SECONDS', 120),

];
