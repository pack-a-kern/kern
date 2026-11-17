<?php

declare(strict_types=1);

// Background-operations console (`/admin/operatiuni`). Everything an operator can tune about the
// scheduler, the run ledger and the freshness thresholds lives here, so none of it is a literal
// buried in a service.
//
// What is deliberately *not* here: which commands may be run. That is
// `Kern\TaxRomania\Operations\Enums\OperationTask`, a closed PHP enum — a config file is data, and data
// that names an artisan command to execute is a remote-code-execution surface. Config decides
// *when* a known task runs; code decides *what* the known tasks are.
return [

    /*
     * The queue for the tasks that read a source's history (`OperationTask::isLong()`), and how long
     * one may run.
     *
     * The `default` queue's worker stops a job at sixty seconds, which suits every other task, and a
     * bank backfill — one ECB list a month since 2017 — or a year of exchange rates does not finish in
     * one: both were stopped there, the backfill with nothing to show for it. Their own queue, one
     * worker, keeps a slow task from holding up the quick ones. Horizon's supervisor for it and the
     * queue connection's `retry_after` read the same two variables (apps/api/config).
     */
    'long_tasks' => [
        'queue' => env('OPERATIONS_LONG_QUEUE', 'operations-long'),
        'timeout_seconds' => (int) env('OPERATIONS_LONG_QUEUE_TIMEOUT_SECONDS', 1800),
    ],

    /*
     * The topbar's queue-activity indicator (`ShowOperationsActivityController`,
     * `OperationsActivityChanged`). The private channel a "look again" nudge is broadcast on — the
     * same one `routes/channels.php` authorizes for `operations.view`; empty broadcasts nowhere, and
     * the indicator falls back to polling only. How long the indicator waits before asking again when
     * no nudge came, in milliseconds — the socket may be down, or a backlog may be draining with no
     * task opening or closing to nudge it.
     */
    'activity' => [
        'broadcast_channel' => (string) env('OPERATIONS_ACTIVITY_CHANNEL', 'operations'),
        'poll_after_ms' => (int) env('OPERATIONS_ACTIVITY_POLL_AFTER_MS', 8000),
    ],

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
            // Just after midnight: a period closes at the end of a day, so the first run after it
            // is the earliest honest moment to post the interest.
            'deposit.post_interest' => env('OPERATIONS_CRON_DEPOSIT_POST_INTEREST', '15 0 * * *'),
            // Monthly: GLEIF publishes its BIC file once a month, and banks change far less often.
            'banks.import' => env('OPERATIONS_CRON_BANKS_IMPORT', '30 5 2 * *'),
            // Weekly, at night: a law gains a consolidation when an amendment enters into force, a
            // few times a year — reading it within the week is soon enough, and the night is quiet.
            'legislation.sync' => env('OPERATIONS_CRON_LEGISLATION_SYNC', '0 3 * * 0'),
            /*
             * Every ten minutes, not every minute. Nobody is waiting: a person who has just imported
             * a statement is reviewing it, and the answers this produces are proposals they will see
             * next time they open the screen. Ten minutes also bounds what a stuck run can overlap
             * with, since one call takes minutes rather than seconds.
             */
            'counterparties.classify' => env('OPERATIONS_CRON_COUNTERPARTIES_CLASSIFY', '*/10 * * * *'),
            // Often, because the cost of being late is a listing that offers to read an inbox whose
            // browser is gone. Cheap: one UPDATE over rows that are almost always none.
            'spv.close_expired_sessions' => env('OPERATIONS_CRON_SPV_CLOSE_EXPIRED_SESSIONS', '*/5 * * * *'),
            // Hourly, at a quiet minute. A row whose session has expired is already unusable, so the
            // only thing being bought is a device list that stops naming it — and an hour of a stale
            // name misleads nobody into anything.
            'identity.prune_sessions' => env('OPERATIONS_CRON_IDENTITY_PRUNE_SESSIONS', '40 * * * *'),
            // Every five minutes: the longest a down service goes unannounced to the administrators.
            'system.health_watch' => env('OPERATIONS_CRON_SYSTEM_HEALTH_WATCH', '*/5 * * * *'),
            // Daily at nine in the schedule's timezone: a reminder is read, not woken to.
            'wallet.maturity_reminders' => env('OPERATIONS_CRON_WALLET_MATURITY_REMINDERS', '0 9 * * *'),
            // ADR 0025: no default — the values are in .env.example, and a schedule missing fails by name.
            'legislation.watch' => env('OPERATIONS_CRON_LEGISLATION_WATCH'),
            'investments.import_government_bonds' => env('OPERATIONS_CRON_INVESTMENTS_IMPORT_GOVERNMENT_BONDS'),
            'investments.import_bvb' => env('OPERATIONS_CRON_INVESTMENTS_IMPORT_BVB'),
            'sources.user_agents' => env('OPERATIONS_CRON_SOURCES_USER_AGENTS'),
            'holdings.post_payouts' => env('OPERATIONS_CRON_HOLDINGS_POST_PAYOUTS'),
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
        // Hard cap on how much of a command's console output is stored on the run row. The output
        // is an operator diagnostic, not a log store.
        'max_output_bytes' => (int) env('OPERATIONS_RUN_MAX_OUTPUT_BYTES', 4000),
    ],

    'health' => [
        // How long the health page waits for each service before calling it unreachable. Short on
        // purpose: the page answers "is it up", and a service that takes longer is not, for a person.
        'timeout_seconds' => (int) env('OPERATIONS_HEALTH_TIMEOUT_SECONDS', 3),
    ],

    'freshness' => [
        // Beyond this many days with no new BNR rate, the FX panel reports stale rather than fine.
        // Four days covers a Friday import plus a Monday public holiday.
        'fx_max_age_days' => (int) env('OPERATIONS_FX_MAX_AGE_DAYS', 4),
        // A document that has sat in a non-terminal status longer than this is stuck, not busy.
        // Horizon's queue depth will not show these: the job already finished, unsuccessfully.
        'document_stuck_after_minutes' => (int) env('OPERATIONS_DOCUMENT_STUCK_AFTER_MINUTES', 60),
    ],

    'legislation' => [
        // The document categories the ingest form offers. In config, not in the enum: an operator
        // adding "CIRCULARA" should not need a code change.
        //
        // Not a domain constraint — `ext_source_documents.document_type` is an unconstrained string,
        // owned by whichever module ingests (the BNR importer writes `FX_DAILY`/`FX_YEARLY` through
        // the same column). This list is only what this screen offers.
        //
        // As the portal files them, without diacritics: an emergency ordinance is its own type,
        // "ORDONANTA DE URGENTA", not an "ORDONANTA".
        //
        // Keep values stable once used: `document_type` is part of
        // `source_documents_identity_unique`, so re-ingesting the same act under a renamed type
        // creates a second document rather than a new version of the first.
        'document_types' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('OPERATIONS_LEGISLATION_DOCUMENT_TYPES', 'LEGE,ORDONANTA,ORDONANTA DE URGENTA,HOTARARE,ORDIN,DECIZIE')),
        ))),

    ],

    // Bounds for the one task that takes an argument (`kern:fx:backfill {year}`). BNR's yearly
    // archives start in 2005 (docs/reference/bnr-fx-endpoints.md); the upper bound is the current
    // year, resolved at request time rather than pinned here.
    'fx_backfill' => [
        'min_year' => (int) env('OPERATIONS_FX_BACKFILL_MIN_YEAR', 2005),
    ],

];
