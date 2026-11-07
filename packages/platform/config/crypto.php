<?php

declare(strict_types=1);

// Milestone 1 crypto core — BLUEPRINT.md §4.2/§4.4, docs/adr/0002-envelope-encryption.md.
//
// Two distinct key classes live here, deliberately kept apart (ADR 0002):
//   - `kms`      : how the per-TaxSubject DEK is wrapped/unwrapped (Key Encryption Key,
//                  held by OpenBao's transit engine in every environment this project runs in).
//   - `blind_index`: the single global, versioned HMAC key used for equality lookups
//                  (login-by-email, duplicate-CNP detection) — never the same key material
//                  as any DEK or KEK.
return [

    'kms' => [
        // Only 'openbao' is a real driver. There is no 'null'/'array' production driver —
        // envelope encryption is not optional (ADR 0002 "security control wins over
        // convenience"). Tests use Kern\Platform\KeyManagement\Contracts\KeyManagementService's
        // in-memory fake (tests/Fakes/InMemoryKeyManagementService.php) constructed directly,
        // never wired through this config.
        'driver' => env('CRYPTO_KMS_DRIVER', 'openbao'),

        'openbao' => [
            // docker/openbao/entrypoint.sh enables the transit engine and creates this exact key
            // (docs/handoff/001-docker-topology.md). Matches KERN_KEK_NAME's default there.
            'address' => env('OPENBAO_ADDR'),
            // One or the other. The bundled server publishes its token to a file and replaces it before
            // it expires (docker/openbao/entrypoint.sh), so `token_file` is set by
            // compose.services/openbao.yaml and wins; `token` is for an OpenBao run elsewhere, whose
            // token is that operator's to manage.
            'token' => env('OPENBAO_TOKEN'),
            'token_file' => env('OPENBAO_TOKEN_FILE'),
            // The CA the server's certificate is verified against, when it is not one the system
            // already trusts. The bundled server's comes from the stack's own CA (docker/certs);
            // compose sets this to that CA's file. Empty means the system's trust store — right for a server with a public
            // certificate, and never a reason to turn verification off.
            'ca_cert' => env('OPENBAO_CA_CERT'),
            // `kern:health` turns key management red when the token has fewer days than this left —
            // which, with the bundled server replacing it at seven, means its daily check has stopped.
            'access_warn_days' => (int) env('OPENBAO_ACCESS_WARN_DAYS', 3),
            'transit_mount' => env('OPENBAO_TRANSIT_MOUNT', 'transit'),
            'tax_subject_kek_name' => env('OPENBAO_TAX_SUBJECT_KEK_NAME', 'tax-subject-kek'),
            'timeout_seconds' => (int) env('OPENBAO_TIMEOUT_SECONDS', 5),
            // The key every blind index is computed with — an HMAC key that, like the KEK, never
            // leaves the server (docs/adr/0020-blind-index-key-in-the-kms.md). Matches
            // KERN_BLIND_INDEX_KEY_NAME's default in docker/openbao/entrypoint.sh.
            'blind_index_key_name' => env('OPENBAO_BLIND_INDEX_KEY_NAME', 'blind-index'),
            // How many values one HMAC request carries. OpenBao refuses a request body of too many
            // JSON strings ("refused JSON input due to high complexity: exceeded maximum number of
            // strings") — each value is two, so 500 at once was refused and 499 accepted (2.6.4, its
            // default listener). An import hashing more is sent in batches of this many, in order.
            'hmac_batch_size' => (int) env('OPENBAO_HMAC_BATCH_SIZE', 250),
        ],
    ],

    // The certificates the stack's own CA issues its services (docker/certs), as `kern:health` sees them.
    'tls' => [
        // The list docker/certs publishes beside its CA: each service's name and when its certificate
        // ends. Set by compose; empty, the health page has no certificates of this installation's own
        // to report on.
        'status_file' => env('TLS_STATUS_FILE'),
        // Each is renewed with 30 days left (TLS_RENEW_DAYS), so one with fewer than this means the
        // renewal has stopped — and the row turns degraded while there is still time to find out why.
        'warn_days' => (int) env('TLS_WARN_DAYS', 14),
    ],

];
