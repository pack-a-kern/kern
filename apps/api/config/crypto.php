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
        // convenience"). Tests use App\Support\KeyManagement\Contracts\KeyManagementService's
        // in-memory fake (tests/Fakes/InMemoryKeyManagementService.php) constructed directly,
        // never wired through this config.
        'driver' => env('CRYPTO_KMS_DRIVER', 'openbao'),

        'openbao' => [
            // docker/openbao/init.sh enables the transit engine and creates this exact key
            // (docs/handoff/001-docker-topology.md). Matches KERN_KEK_NAME's default there.
            'address' => env('OPENBAO_ADDR', 'http://openbao:8200'),
            'token' => env('OPENBAO_TOKEN', env('OPENBAO_DEV_ROOT_TOKEN')),
            'transit_mount' => env('OPENBAO_TRANSIT_MOUNT', 'transit'),
            'tax_subject_kek_name' => env('OPENBAO_TAX_SUBJECT_KEK_NAME', 'tax-subject-kek'),
            'timeout_seconds' => (int) env('OPENBAO_TIMEOUT_SECONDS', 5),
        ],
    ],

    'blind_index' => [
        // Versioned so the key can be rotated (ADR 0002: rotation means recomputing every
        // blind index under the new version before the old one retires — a real batch job).
        //
        // CRYPTO_BLIND_INDEX_KEYS is a JSON object mapping version => base64-encoded 32-byte
        // key, e.g. {"1":"<base64 32 bytes>"}. Never a bare passphrase — HMAC-SHA-256 needs a
        // real 256-bit key, not a short password (BLUEPRINT.md §4.4 forbids a bare hash for
        // low-entropy input; the same reasoning means the key itself must be high-entropy).
        'keys' => env('CRYPTO_BLIND_INDEX_KEYS'),
        'current_version' => (int) env('CRYPTO_BLIND_INDEX_CURRENT_VERSION', 1),
    ],

];
