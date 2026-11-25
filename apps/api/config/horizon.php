<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_') . '_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    | Restricted further per blueprint §35 before production (dashboard must
    | not be reachable by unauthenticated/unauthorised users).
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        // Generous on purpose: this queue is serial and each job holds it for as long as the model
        // takes, so a short backlog is normal operation rather than something to alert on. Ten
        // documents at a minute each is ten minutes of honest waiting. Keyed by the connection its
        // supervisor reads through — under `redis:` this threshold silently stopped applying.
        'redis-ai:' . env('AI_QUEUE', 'documents-ai') => 1800,
        // A law is ninety consolidations read one after another: an hour's backlog is a sync in
        // progress, not a stuck queue.
        'redis-legislation:' . env('LEGISLATION_QUEUE', 'legislation') => 3600,
        // A watch run is minutes on the portal, one after another.
        'redis-legislation:' . env('LEGISLATION_WATCHES_QUEUE') => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [],

    'silenced_tags' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        /*
         * Reading a document is serialised: one supervisor, one process, its own queue.
         *
         * See `Kern\Documents\Jobs\ProcessScannedDocument::$queue`. `maxProcesses` is 1 in
         * every environment, deliberately not scaled with the others — the constraint is a single
         * model endpoint, not this machine's cores.
         *
         * `timeout` must stay above every job's own: the document reader's, derived from
         * `AI_EXTRACTION_TIMEOUT_SECONDS`, and the statement import's, derived from
         * `STATEMENT_IMPORT_AI_TIMEOUT_SECONDS` (plus the reader's two calls and the PDF conversion, for
         * the job that reads the file and then asks — 120 × 2 + 300 + 600 + 60 = 1 200 by default). A
         * supervisor timeout below either kills the job mid-request and records nothing about why.
         */
        'supervisor-documents-ai' => [
            // Its own connection for its reservation length — see `queue.connections.redis-ai`.
            'connection' => 'redis-ai',
            // `ai.providers.queue` — the one queue every model call runs on.
            'queue' => [env('AI_QUEUE', 'documents-ai')],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => (int) env('HORIZON_AI_QUEUE_TIMEOUT_SECONDS', 1500),
            'nice' => 0,
        ],

        /*
         * Reading a law: one process, its own queue, minutes per job. A consolidation of the Codul
         * fiscal is ~5 MB to download and some 7 000 sections to parse and index; the default
         * supervisor's timeout would kill that part-way.
         */
        'supervisor-legislation' => [
            'connection' => 'redis-legislation',
            'queue' => [env('LEGISLATION_QUEUE', 'legislation')],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            // The same variable `legislation.job_timeout_seconds` reads. A config file cannot read
            // another's — Laravel loads them alphabetically, and this one comes first — so the
            // agreement is the env var, not a reference. Raising it in one place raises all three: the
            // package's jobs, this worker, and the queue's `retry_after`.
            'timeout' => (int) env('LEGISLATION_QUEUE_TIMEOUT_SECONDS', 1800),
            'nice' => 0,
        ],

        /*
         * A watch run a person asked for: minutes of searching the portal, on a worker of its own so the
         * laws it starts are read meanwhile rather than after it. The legislation supervisor's connection
         * and timeout: the job is held to `legislation.job_timeout_seconds` too.
         */
        'supervisor-legislation-watches' => [
            'connection' => 'redis-legislation',
            'queue' => [env('LEGISLATION_WATCHES_QUEUE')],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => (int) env('LEGISLATION_QUEUE_TIMEOUT_SECONDS', 1800),
            'nice' => 0,
        ],

        /*
         * The tasks that read a source's history — a bank backfill, a year of exchange rates — which
         * the default supervisor's sixty seconds stopped part-way (`OperationTask::isLong()`). One
         * process: they download from one publisher at a time, and one at a time is enough.
         */
        'supervisor-operations-long' => [
            'connection' => 'redis-operations-long',
            'queue' => [env('OPERATIONS_LONG_QUEUE', 'operations-long')],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            // The same variable `operations.long_tasks.timeout_seconds` reads, for the same reason the
            // legislation supervisor shares its own: this file cannot read the package's config.
            'timeout' => (int) env('OPERATIONS_LONG_QUEUE_TIMEOUT_SECONDS', 1800),
            'nice' => 0,
        ],

        /*
         * Reading one BVB instrument's page in a browser (`Kern\Investments\Jobs\ReadBvbInstrument`):
         * its chart's history the first time, then its issuer profile. One process: the reads open a
         * real browser on BVB's site, and more than one at a time only piles sessions onto the browser
         * worker — each read then takes longer than it does alone, past the default queue's sixty
         * seconds. One at a time, spaced by `INVESTMENTS_BVB_SPACING_SECONDS`, is both gentler on BVB
         * and faster per read. Its own timeout, generous enough for a page whose chart never draws and
         * the browser waits out, then the profile on top.
         */
        'supervisor-investments-bvb' => [
            'connection' => 'redis-investments-bvb',
            'queue' => [env('INVESTMENTS_QUEUE', 'investments-bvb')],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => (int) env('HORIZON_INVESTMENTS_BVB_TIMEOUT_SECONDS', 180),
            'nice' => 0,
        ],

        /*
         * Writing a reviewed statement into the ledger (`Kern\TaxRomania\StatementImport\Jobs\
         * WriteStatementRows`): a large import seals and blind-indexes every row, which the default
         * supervisor's sixty seconds stopped part-way. Its own lane, one process — a person commits
         * one import at a time — and a timeout generous enough for a few thousand rows.
         */
        'supervisor-statement-import' => [
            'connection' => 'redis-statement-import',
            'queue' => [env('STATEMENT_IMPORT_COMMIT_QUEUE', 'statement-import')],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            // The same variable the job's own timeout and the queue's `retry_after` read, so the three
            // never disagree — this file cannot read the package's config.
            'timeout' => (int) env('STATEMENT_IMPORT_COMMIT_TIMEOUT_SECONDS', 1800),
            'nice' => 0,
        ],

        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            // Must stay below REDIS_QUEUE_RETRY_AFTER, or a slow job is handed out a second time.
            // Model calls do not run here: anything slow belongs on the model queue above.
            'timeout' => (int) env('HORIZON_DEFAULT_QUEUE_TIMEOUT_SECONDS', 60),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-documents-ai' => [
                'maxProcesses' => 1,
            ],
            'supervisor-legislation' => [
                'maxProcesses' => 1,
            ],
            'supervisor-legislation-watches' => [
                'maxProcesses' => 1,
            ],
            'supervisor-operations-long' => [
                'maxProcesses' => 1,
            ],
            'supervisor-investments-bvb' => [
                'maxProcesses' => 1,
            ],
            'supervisor-statement-import' => [
                'maxProcesses' => 1,
            ],
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-documents-ai' => [
                'maxProcesses' => 1,
            ],
            'supervisor-legislation' => [
                'maxProcesses' => 1,
            ],
            'supervisor-legislation-watches' => [
                'maxProcesses' => 1,
            ],
            'supervisor-operations-long' => [
                'maxProcesses' => 1,
            ],
            'supervisor-investments-bvb' => [
                'maxProcesses' => 1,
            ],
            'supervisor-statement-import' => [
                'maxProcesses' => 1,
            ],
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
