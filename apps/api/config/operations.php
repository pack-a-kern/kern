<?php

declare(strict_types=1);

// Background-operations console (`/admin/operatiuni`). Everything an operator can tune about the
// scheduler, the run ledger and the freshness thresholds lives here, so none of it is a literal
// buried in a service.
//
// What is deliberately *not* here: which commands may be run. That is
// `App\Domain\Operations\Enums\OperationTask`, a closed PHP enum — a config file is data, and data
// that names an artisan command to execute is a remote-code-execution surface. Config decides
// *when* a known task runs; code decides *what* the known tasks are.
return [

    // The scheduler beats twice a minute into two different places, because two audiences ask the
    // question for different reasons and must fail independently.
    'scheduler' => [
        // 1. The file, for `docker/php/scheduler-healthcheck.php` — the container's own liveness.
        //    `/tmp` rather than `storage/`: a liveness marker must not outlive the process that
        //    writes it, and `/tmp` is the only path this hardened image lets the runtime user
        //    write to. This is deliberately a *local* signal: a healthcheck that went red because
        //    Redis was down would restart a scheduler that is working perfectly.
        'heartbeat_path' => env('OPERATIONS_HEARTBEAT_PATH', '/tmp/scheduler-heartbeat'),

        // 2. The cache key, for the operations screen — which is rendered by the `app` container,
        //    a different container with its own tmpfs. It cannot see the file above at all, so
        //    without this the screen reports a healthy scheduler as stalled. The cache is the one
        //    channel both containers already share.
        'heartbeat_cache_key' => env('OPERATIONS_HEARTBEAT_CACHE_KEY', 'operations:scheduler-heartbeat'),
        // Long enough that the age is still reportable well after a stall began — an expired key
        // can only say "stalled", while a stored timestamp can say "stalled for six hours".
        'heartbeat_cache_ttl_seconds' => (int) env('OPERATIONS_HEARTBEAT_CACHE_TTL_SECONDS', 86400),

        // Three missed minutes. One missed tick is a slow container, not an outage.
        'heartbeat_max_age_seconds' => (int) env('OPERATIONS_HEARTBEAT_MAX_AGE_SECONDS', 180),
    ],

    // Cron expressions and the timezone they are read in. Editable by an operator through env, and
    // reviewable in one place — never through a text field on the administration screen, where a
    // typo silently stops a job forever with no error anywhere.
    'schedule' => [
        'timezone' => env('OPERATIONS_SCHEDULE_TIMEZONE', 'Europe/Bucharest'),
        'expressions' => [
            // Weekdays at 14:00 Bucharest — BNR publishes the daily reference rates at 13:00.
            'fx.import_daily' => env('OPERATIONS_CRON_FX_IMPORT_DAILY', '0 14 * * 1-5'),
        ],
    ],

    'runs' => [
        // A manual run is dispatched onto this queue rather than run inside the HTTP request:
        // every task here takes minutes.
        'queue' => env('OPERATIONS_RUN_QUEUE', 'default'),
        // How long the per-task lock is held while a run is in flight, so a double-click cannot
        // start two imports over the same day. Longer than the slowest task, and self-expiring so
        // a crashed worker cannot wedge a task permanently.
        'lock_seconds' => (int) env('OPERATIONS_RUN_LOCK_SECONDS', 1800),
        // Rows shown per page in the run history.
        'page_size' => (int) env('OPERATIONS_RUNS_PAGE_SIZE', 20),
        // Hard cap on how much of a command's console output is stored on the run row. The output
        // is an operator diagnostic, not a log store.
        'max_output_bytes' => (int) env('OPERATIONS_RUN_MAX_OUTPUT_BYTES', 4000),
    ],

    // The background-activity feed merges `job_runs` with Laravel's `failed_jobs` in PHP rather than
    // in SQL (see App\Domain\Operations\Services\ReadBackgroundActivity for why), so paging is
    // bounded by how many rows it reads. This is how far back the feed can see.
    'activity' => [
        'window' => (int) env('OPERATIONS_ACTIVITY_WINDOW', 200),
    ],

    'freshness' => [
        // Beyond this many days with no new BNR rate, the FX panel reports stale rather than fine.
        // Four days covers a Friday import plus a Monday public holiday.
        'fx_max_age_days' => (int) env('OPERATIONS_FX_MAX_AGE_DAYS', 4),
        // A document that has sat in a non-terminal status longer than this is stuck, not busy.
        // Horizon's queue depth will not show these: the job already finished, unsuccessfully.
        'document_stuck_after_minutes' => (int) env('OPERATIONS_DOCUMENT_STUCK_AFTER_MINUTES', 60),
        // Failed jobs are listed newest first; the screen never pretends to page through thousands.
        'failed_jobs_limit' => (int) env('OPERATIONS_FAILED_JOBS_LIMIT', 50),
    ],

    'legislation' => [
        // The document categories the ingest form offers. In config, not in the enum: an operator
        // adding "CIRCULARA" should not need a code change.
        //
        // Not a domain constraint — `source_documents.document_type` is an unconstrained string,
        // owned by whichever module ingests (the BNR importer writes `FX_DAILY`/`FX_YEARLY` through
        // the same column). This list is only what this screen offers.
        //
        // Keep values stable once used: `document_type` is part of
        // `source_documents_identity_unique`, so re-ingesting the same act under a renamed type
        // creates a second document rather than a new version of the first.
        'document_types' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('OPERATIONS_LEGISLATION_DOCUMENT_TYPES', 'LEGE,ORDONANTA,HOTARARE,ORDIN,DECIZIE')),
        ))),
    ],

    // Bounds for the one task that takes an argument (`taxos:fx:backfill {year}`). BNR's yearly
    // archives start in 2005 (docs/reference/bnr-fx-endpoints.md); the upper bound is the current
    // year, resolved at request time rather than pinned here.
    'fx_backfill' => [
        'min_year' => (int) env('OPERATIONS_FX_BACKFILL_MIN_YEAR', 2005),
    ],

];
