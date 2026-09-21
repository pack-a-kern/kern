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

    'form' => [
        // The earliest year the "Ingest legislation" task offers. The portal holds acts back to the
        // nineteenth century; the tax law this application reads is far more recent.
        'min_year' => (int) env('LEGISLATION_MIN_YEAR', 1990),
    ],
];
