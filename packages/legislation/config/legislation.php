<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Legislation
|--------------------------------------------------------------------------
|
| How a law is read from legislatie.just.ro: found through the portal's web service by type,
| number and year, then every one of its consolidations downloaded, dated and parsed. The portal's
| address and its web service are `config/sources.php`'s `LEGISLATIE` entry.
|
| Reading a law is queued work — the Codul fiscal is some ninety consolidations of 5 MB each — so it
| runs on a queue of its own, one consolidation per job, where a job may take minutes.
*/

return [

    'queue' => env('LEGISLATION_QUEUE', 'legislation'),

    /*
     * How long one consolidation may take.
     *
     * Here, not in the application's Horizon supervisor, although the supervisor must agree with it —
     * a worker killed at 1800 seconds running a job that thinks it has an hour loses the work and
     * reports nothing. The jobs used to read the supervisor's own timeout, which meant reading a law
     * required a queue dashboard to be configured; installing this package on its own is what found
     * that. Both now read the same environment variable, which is the only way two config files can
     * agree — Laravel loads them alphabetically, so neither can read the other.
     */
    'job_timeout_seconds' => (int) env('LEGISLATION_QUEUE_TIMEOUT_SECONDS', 1800),

    'sync' => [
        /*
         * The pauses before a consolidation is tried again when the portal did not answer, in
         * seconds — one entry per retry. The portal stalls and drops connections under load; a
         * consolidation that still fails is marked and the next one read, so one bad page never
         * stops a law.
         */
        'retry_seconds' => array_values(array_map('intval', array_filter(explode(',', (string) env('LEGISLATION_SYNC_RETRY_SECONDS', '30,120,300'))))),
    ],

    'embedding' => [
        // Chunks sent to the embedding model at once. A text already embedded anywhere — the same
        // provision, unchanged, in the previous consolidation — is copied, never embedded again.
        'batch' => (int) env('LEGISLATION_EMBEDDING_BATCH', 256),
    ],

    /*
     * How "Find a provision" ranks what it finds (`HybridLegislationSearch`). No default for any of these
     * (ADR 0025 §4): the values are in .env.example, and one missing or out of range fails by name.
     *
     * A result's score is meaning × its weight + words × its weight + label × its weight, each part from 0
     * to 1. A part only counts as a match above its minimum: a label like "art 43" close enough to
     * "Art. 43", a paragraph close enough in meaning. Words count whenever they match at all.
     */
    'search' => [
        'weight_meaning' => env('LEGISLATION_SEARCH_WEIGHT_MEANING'),
        'weight_words' => env('LEGISLATION_SEARCH_WEIGHT_WORDS'),
        'weight_label' => env('LEGISLATION_SEARCH_WEIGHT_LABEL'),
        'min_label' => env('LEGISLATION_SEARCH_MIN_LABEL'),
        'min_meaning' => env('LEGISLATION_SEARCH_MIN_MEANING'),
        // Results shown when none are asked for, and the most that may be asked for.
        'results' => env('LEGISLATION_SEARCH_RESULTS'),
        'max_results' => env('LEGISLATION_SEARCH_MAX_RESULTS'),
    ],

    /*
     * Searches per account. Each one embeds the query on this server's CPU; typing a question and
     * refining it a few times is far inside this, a loop is not.
     */
    'rate_limits' => [
        'search_per_user' => [
            'max_attempts' => (int) env('LEGISLATION_SEARCH_RATE_LIMIT_MAX_ATTEMPTS', 30),
            'decay_seconds' => (int) env('LEGISLATION_SEARCH_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
    ],

    'notes' => [
        /*
         * What the portal's notes ("Notă") say about when a provision applies. The consolidation's
         * own date is when the portal's text changed; an amending act often says its change applies
         * later — "se aplică începând cu veniturile aferente anului 2026" — and the portal copies
         * that sentence into a note on the provision. Measured on the 2026-08-08 consolidation of the
         * Codul fiscal: 212 of its 301 notes say something of the kind.
         *
         * Each pattern is matched case-insensitively, in this order; a later one never re-reads words
         * an earlier one already took. `{date}`, `{month}`, `{year}` and `{quarter}` stand for the
         * fragments below and are captured by those names; `{until}` is a second date, the end of a
         * period. What a match means for its dates is its kind's, in `NoteApplicability`.
         */
        'applicability' => [
            ['kind' => 'income_month_after_publication', 'pattern' => 'venituri\p{L}*(?:\s+[\p{L},]+){0,6}?\s+aferente\s+lunii\s+următoare\s+publicării'],
            ['kind' => 'income_month', 'pattern' => 'venituri\p{L}*(?:\s+[\p{L},]+){0,6}?\s+aferente\s+lunii\s+{month}\s+(?:a\s+anului\s+)?{year}'],
            ['kind' => 'income_year', 'pattern' => 'venituri\p{L}*(?:\s+[\p{L},]+){0,6}?\s+aferente\s+anului\s+(?:fiscal\s+)?{year}'],
            ['kind' => 'modified_fiscal_year', 'pattern' => 'anul\s+fiscal\s+modificat\s+care\s+începe\s+în\s+anul\s+{year}'],
            ['kind' => 'fiscal_year', 'pattern' => '(?:începând\s+cu|pentru\s+întregul)\s+an(?:ul)?\s+fiscal\s+{year}'],
            ['kind' => 'quarter', 'pattern' => 'începând\s+cu\s+trimestrul\s+{quarter}\s+(?:al\s+anului\s+)?{year}'],
            ['kind' => 'in_force', 'pattern' => 'intră\s+în\s+vigoare\s+(?:la\s+data\s+de|începând\s+cu(?:\s+data\s+de)?|la)\s+{date}'],
            ['kind' => 'in_force_on_publication', 'pattern' => 'intră\s+în\s+vigoare\s+la\s+data\s+publicării'],
            ['kind' => 'from_date', 'pattern' => 'începând\s+cu(?:\s+data\s+de)?\s+{date}(?:\s*,?\s*(?:și\s+)?până\s+la(?:\s+data\s+de)?\s+{until}(?:\s+inclusiv)?)?'],
            ['kind' => 'until_date', 'pattern' => 'până\s+la\s+data\s+de\s+{until}(?:\s+inclusiv)?'],
            ['kind' => 'on_date', 'pattern' => 'la\s+data\s+de\s+{date}'],
        ],

        'fragments' => [
            'date' => '\d{1,2}\s+(?:ianuarie|februarie|martie|aprilie|mai|iunie|iulie|august|septembrie|octombrie|noiembrie|decembrie)\s+\d{4}',
            'month' => 'ianuarie|februarie|martie|aprilie|mai|iunie|iulie|august|septembrie|octombrie|noiembrie|decembrie',
            'year' => '\d{4}',
            'quarter' => 'I|II|III|IV',
        ],

        /*
         * The act a note quotes — "ORDONANȚA DE URGENȚĂ nr. 8 din 24 februarie 2026, publicată în
         * MONITORUL OFICIAL nr. 147 din 25 februarie 2026". A note may quote several; each date it
         * gives belongs to the last act named before it.
         */
        'act' => '(?<type>LEGEA|LEGE|ORDONANȚA\s+DE\s+URGENȚĂ|ORDONANȚĂ\s+DE\s+URGENȚĂ|ORDONANȚA|ORDONANȚĂ|HOTĂRÂREA|HOTĂRÂRE|ORDINUL|ORDIN|DECIZIA\s+CURȚII\s+CONSTITUȚIONALE|DECIZIA|DECIZIE|RECTIFICAREA|RECTIFICARII|RECTIFICARE)\s+nr\.\s*(?<number>\d+)\s+din\s+(?<date>{date}),?\s+publicat(?:ă|a|e)?\s+în\s+Monitorul\s+Oficial(?:\s+al\s+României)?(?:,?\s+Partea\s+I)?,?\s+nr\.\s*(?<journal_number>\d+(?:\s*bis)?)\s+din\s+(?<journal_date>{date})',
    ],

    /*
     * A search of the portal from the screen (or from another package): a record, read a page a job
     * (ADR 0024), so it is never a request waiting on the portal and can take as long as the portal does.
     */
    /*
     * Searches of the portal run again and again, each new act read as a law (`Kern\Legislation\Watches`).
     * No default for any of these (ADR 0025 §4): the values are in .env.example, and one missing fails
     * by name. `declared` is filled by the packages that watch for something — each adds its own from
     * its provider — never written here.
     */
    'watches' => [
        // The queue a watch run asked for now is worked on: its own, so a run of minutes on the portal never
        // holds up the reading of the laws it, or anything else, has started.
        'queue' => env('LEGISLATION_WATCHES_QUEUE'),
        // How long one watch's run may hold its lock, in seconds: a slow search is never run twice at once.
        'lock_seconds' => env('LEGISLATION_WATCHES_LOCK_SECONDS'),
        // An act of late December is published in January: while the year is younger than this many
        // days, last year is searched again too.
        'overlap_days' => env('LEGISLATION_WATCHES_OVERLAP_DAYS'),
        // The intervals, in hours, a person may choose for a watch of their own.
        'intervals' => env('LEGISLATION_WATCHES_INTERVALS'),
        // The most new acts one watch starts reading in one run — the rest wait for its next run — and the
        // most watches one person may keep: a search too broad must not read the whole portal at once.
        'max_new_acts' => env('LEGISLATION_WATCHES_MAX_NEW_ACTS'),
        'max_per_person' => env('LEGISLATION_WATCHES_MAX_PER_PERSON'),
        'declared' => [],
    ],

    'imports' => [
        // The most acts one "Import selected" queues at once, each read on the watches' queue
        // (`watches.queue`), one after another: a choice from a search, not a crawl of the portal.
        'max_acts' => env('LEGISLATION_IMPORT_MAX_ACTS'),
    ],

    'portal_search' => [
        // The queue its pages are read on. Not this package's own `queue`, where a consolidation holds a
        // worker for minutes: a search is a person waiting, a page at a time, a few seconds each.
        'queue' => (string) env('LEGISLATION_PORTAL_SEARCH_QUEUE', 'default'),
        // How long one page may take: a browser for the page and its own retries on the site's error
        // page. Under the timeout of the supervisor that works its queue — sixty seconds for `default`.
        'job_timeout_seconds' => (int) env('LEGISLATION_PORTAL_SEARCH_JOB_TIMEOUT_SECONDS', 55),
        // The pauses before a page the portal still would not answer is asked again, in seconds — one
        // entry per retry. Spent, the search ends incomplete at that page, saying so.
        'retry_seconds' => array_values(array_map('intval', array_filter(explode(',', (string) env('LEGISLATION_PORTAL_SEARCH_RETRY_SECONDS', '10,30,60'))))),
        // How long a search is kept after it was started, for whoever is still reading its results.
        // Older ones are removed when their owner starts the next.
        'keep_hours' => (int) env('LEGISLATION_PORTAL_SEARCH_KEEP_HOURS', 24),
        // How soon a screen asks for a running search again when no message came over the socket — the
        // socket may be down, or a message lost; the answer is the same either way.
        'poll_after_ms' => (int) env('LEGISLATION_PORTAL_SEARCH_POLL_AFTER_MS', 5000),
        // The private channel a search's progress is broadcast on, `{user}` for whoever started it — the
        // application's own per-person channel. Empty to broadcast nothing; a search with no owner never is.
        'broadcast_channel' => (string) env('LEGISLATION_PORTAL_SEARCH_CHANNEL', 'users.{user}'),
    ],

    'form' => [
        // The earliest year an act is looked for in, on the "Add a law" dialog and by the ingest
        // task; the latest is always the current one. The portal holds acts back to the nineteenth
        // century; the tax law this application reads starts with the Codul fiscal of 2015.
        'min_year' => (int) env('LEGISLATION_MIN_YEAR', 2015),
    ],

    // How the setup screen (ADR 0027) waits while an import it started runs: it re-asks readiness
    // every `poll_ms`, gives up spinning on a step that has not landed after `poll_ceiling_ms`, and —
    // once every required piece is in — pauses `ready_redirect_ms` on the "complete" banner before it
    // sends the person to the dashboard. Served to the screen by `ShowRequirementsController`, so the
    // cadence lives here and not as a number written into the frontend.
    'setup' => [
        'poll_ms' => (int) env('LEGISLATION_SETUP_POLL_MS', 3000),
        'poll_ceiling_ms' => (int) env('LEGISLATION_SETUP_POLL_CEILING_MS', 180000),
        'ready_redirect_ms' => (int) env('LEGISLATION_SETUP_READY_REDIRECT_MS', 1200),
    ],
];
