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

    'form' => [
        // The earliest year an act is looked for in, on the "Add a law" dialog and by the ingest
        // task; the latest is always the current one. The portal holds acts back to the nineteenth
        // century; the tax law this application reads starts with the Codul fiscal of 2015.
        'min_year' => (int) env('LEGISLATION_MIN_YEAR', 2015),
    ],
];
