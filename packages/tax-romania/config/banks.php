<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Banks
|--------------------------------------------------------------------------
|
| What `Kern\TaxRomania\Banks\Actions\ImportBanks` keeps from the ECB's list of
| financial institutions. Where the lists are downloaded from is not here: it
| is the ECB and GLEIF entries in `config/sources.php`, beside every other
| external download, so one allowlist governs them all.
|
| Both values are comma-separated in `.env`. The ECB list covers every EU
| country, so widening `BANKS_COUNTRIES` to `RO,BG` and running the import
| again is the whole change — no code, no migration.
|
*/

$list = static fn (string $value): array => array_values(array_filter(
    array_map(static fn (string $item): string => trim($item), explode(',', $value)),
    static fn (string $item): bool => $item !== '',
));

return [

    // ISO 3166-1 alpha-2 codes, as the ECB file writes them.
    'countries' => array_map('strtoupper', $list((string) env('BANKS_COUNTRIES', 'RO'))),

    // The ECB file's own category names. "Credit Institution" is what takes deposits; the same file
    // also lists central banks and money market funds, which nobody opens a deposit with.
    'categories' => $list((string) env('BANKS_CATEGORIES', 'Credit Institution')),

    /*
     * Banks that closed or merged before the first import, read from the ECB's archived lists.
     *
     * One list a month, from `from` up to the first day already imported: a bank that lived for
     * weeks between two firsts of the month is missed, and a month is 12 downloads a year rather
     * than 250. A month's first list is the first working day's, so up to `days_to_try` days are
     * tried before the month is reported as missing.
     */
    'backfill' => [
        'from' => env('BANKS_BACKFILL_FROM', '2017-01'),
        'days_to_try' => (int) env('BANKS_BACKFILL_DAYS_TO_TRY', 7),
    ],

];
