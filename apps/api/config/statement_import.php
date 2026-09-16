<?php

declare(strict_types=1);

/**
 * Importing a bank's own statement into transactions.
 *
 * Every value here is an engineering limit or a service address — none is fiscal, so none needs a
 * source section. They are configuration rather than constants because the right ceiling depends on
 * the installation: a person importing five years of history needs a different row limit from one
 * importing last month, and neither should need a deployment to change it.
 *
 * See `docs/handoff/045-statement-import.md` for the design these values serve.
 */

return [

    /*
     * The reader. Statement files are parsed in the processing plane, next to the PDF text
     * extraction, for three reasons: the bytes are untrusted, that container holds no database
     * credentials, and the PHP image has no `zip` extension for `.xlsx` (`docker/php/Dockerfile`).
     */
    'parser' => [
        // The address and token are `services.processing`. Generous next to a document read: a
        // workbook with thousands of rows is slow to open, and this runs in a queue job rather than
        // in front of a person waiting on a request.
        'timeout_seconds' => (int) env('STATEMENT_PARSE_TIMEOUT_SECONDS', 120),
    ],

    /*
     * How long a freshly uploaded statement is waited on while the antivirus scans it.
     *
     * The job checks, and if the file is still in quarantine puts itself back for
     * `seconds_between_checks`, up to `checks` times — about five minutes by default. Long enough for
     * ClamAV reloading its signatures; past that, a scan is stuck rather than slow, and the import
     * fails honestly instead of waiting forever.
     */
    'scan_wait' => [
        'seconds_between_checks' => (int) env('STATEMENT_IMPORT_SCAN_WAIT_SECONDS', 15),
        'checks' => (int) env('STATEMENT_IMPORT_SCAN_WAIT_CHECKS', 20),
    ],

    /*
     * Month names, for dates a bank writes as "12 septembrie 2026".
     *
     * In configuration because they are language, not logic: a Hungarian or German export needs its
     * own list, and adding one must not be a code change. Compared without diacritics and in lower
     * case, so "Ştefan"-style spellings and shouty exports both match.
     */
    'months' => [
        'ianuarie' => 1, 'ian' => 1, 'january' => 1, 'jan' => 1,
        'februarie' => 2, 'feb' => 2, 'february' => 2,
        'martie' => 3, 'mar' => 3, 'march' => 3,
        'aprilie' => 4, 'apr' => 4, 'april' => 4,
        'mai' => 5, 'may' => 5,
        'iunie' => 6, 'iun' => 6, 'june' => 6, 'jun' => 6,
        'iulie' => 7, 'iul' => 7, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'septembrie' => 9, 'sep' => 9, 'sept' => 9, 'september' => 9,
        'octombrie' => 10, 'oct' => 10, 'october' => 10,
        'noiembrie' => 11, 'noi' => 11, 'nov' => 11, 'november' => 11,
        'decembrie' => 12, 'dec' => 12, 'december' => 12,
    ],

    /*
     * What a statement may be.
     *
     * The list is checked at upload, so a format that is turned off here is refused before anything
     * reads it. MT940 and CAMT.053 are deliberately absent: they are exact formats that need no
     * column matching at all, and they belong to their own reader rather than to this one.
     */
    'formats' => ['CSV', 'XLSX', 'XLS', 'PDF'],

    'limits' => [
        /*
         * The largest file the upload accepts, checked before a byte is stored.
         *
         * The reader has ceilings of its own — rows, pages, bytes — and they are the ones that
         * decide whether a file can be read (`STATEMENT_PARSE_*`, set on the python-api container).
         * This one exists earlier in the story: it stops an upload that could never be read from
         * being scanned, encrypted and stored first.
         */
        'max_file_bytes' => (int) env('STATEMENT_IMPORT_MAX_FILE_BYTES', 10_485_760),
        // Rows shown while a person confirms which column is which.
        'preview_rows' => (int) env('STATEMENT_IMPORT_PREVIEW_ROWS', 20),
        /*
         * Transactions rebuilt from those rows and shown as the form is filled in.
         *
         * Two or three is the useful number: enough to see a detail line folded into the line above
         * and the two directions read differently, few enough to take in at a glance.
         */
        'preview_transactions' => (int) env('STATEMENT_IMPORT_PREVIEW_TRANSACTIONS', 3),
        /*
         * How much of the file a mapping has to actually read before it can be used.
         *
         * A mapping that parses four rows in five is not a mapping, it is a guess that happens to
         * fit the top of the file — and the rows it cannot read would arrive as transactions with no
         * date or no amount. Refused, with the count shown, so the person fixes the column rather
         * than importing a fifth of a statement.
         */
        'parse_threshold' => (float) env('STATEMENT_IMPORT_PARSE_THRESHOLD', 0.95),
        // Rows saved per database transaction by the commit job.
        'commit_chunk' => (int) env('STATEMENT_IMPORT_COMMIT_CHUNK', 500),
        'max_tags_per_row' => (int) env('STATEMENT_IMPORT_MAX_TAGS_PER_ROW', 3),
    ],

];
