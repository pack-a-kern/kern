<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default hash driver
    |--------------------------------------------------------------------------
    |
    | `BLUEPRINT.md` §4.7: passwords are Argon2id, not bcrypt (Laravel's own
    | unpublished default). The base image's `php:8.5-fpm-trixie` already
    | compiles `--with-password-argon2` (docs/reference/verified-versions.md),
    | so no extension or library is added for this — PHP's own
    | `password_hash()`/`password_verify()` under Laravel's `Argon2IdHasher`.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon2id options
    |--------------------------------------------------------------------------
    |
    | PHP's own `PASSWORD_ARGON2_DEFAULT_*` constants (memory 64 MiB, 4
    | iterations, 1 thread) — this is the same default Laravel's own
    | published `config/hashing.php` stub ships, not a project-specific
    | tuning; revisit only with a measured latency/throughput budget, not a
    | guess.
    |
    */

    'argon' => [
        'memory' => (int) env('HASH_ARGON_MEMORY', 65536),
        'threads' => (int) env('HASH_ARGON_THREADS', 1),
        'time' => (int) env('HASH_ARGON_TIME', 4),
        'verify' => true,
    ],

];
