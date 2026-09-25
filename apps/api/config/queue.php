<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Blueprint §1.1: Redis is the sole queue backend, driven by Horizon.
    | "sync" exists only for the test suite (phpunit.xml QUEUE_CONNECTION).
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Must stay above the default queue's timeout (HORIZON_DEFAULT_QUEUE_TIMEOUT_SECONDS).
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        /*
         * The same Redis queues, read with a reservation long enough for a model call.
         *
         * A reservation that outlives `retry_after` is treated as a lost job and handed out again. At
         * 90 seconds, a five-minute model call would be picked up a second time by any other process
         * on its queue — two identical calls, and a failure recorded for the one that succeeded.
         * It has not happened only because the model queue has one process, which is busy with the
         * call itself.
         *
         * The reservation is set by the worker that takes the job, not by the code that queued it, so
         * only the model supervisor names this connection; jobs keep pushing through `redis`.
         */
        'redis-ai' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('HORIZON_AI_QUEUE_TIMEOUT_SECONDS', 1500) + (int) env('AI_JOB_TIMEOUT_MARGIN_SECONDS', 60),
            'block_for' => null,
            'after_commit' => false,
        ],

        // The same, for reading a law: one consolidation downloaded, parsed and indexed per job.
        'redis-legislation' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // A minute past what one of those jobs is allowed to take (the same env var
            // `legislation.job_timeout_seconds` reads), so a released job is never retried while it
            // is still running.
            'retry_after' => (int) env('LEGISLATION_QUEUE_TIMEOUT_SECONDS', 1800) + 60,
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | Failed jobs are recorded in Postgres, not Redis, so they survive a
    | queue flush and remain available for audit (blueprint §23).
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
