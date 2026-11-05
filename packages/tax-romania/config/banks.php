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
     * What kind of institution a code says this is, per country.
     *
     * The ECB's own `CATEGORY` is `Credit Institution` for everything we keep, so it draws no line
     * between a cooperative in Slatina, a branch of ING and Banca Comercială Română. The line is in
     * the registration number, which the national central bank assigns and the ECB passes through —
     * letters for Romania, digits for Germany and France. So the table is keyed by country: a code
     * means nothing outside the register that issued it.
     *
     * ## Written as the regulation writes them
     *
     * `RB-PJR`, not `RORBPJR`. The file prints these without punctuation and sometimes behind the
     * country code, so both sides are stripped of anything but letters and digits and lowercased
     * before they are compared, and a pattern matches at the start of the code or at the start of
     * what follows the country code. That way this list reads like its source rather than like the
     * file, and can be checked against the source by eye.
     *
     * ## The source, and how sure it is
     *
     * These are from Regulamentul BNR nr. 1/2007 privind registrul instituțiilor de credit, art. 7,
     * which was repealed on 23 December 2020 by Regulamentul BNR nr. 12/2020 — where the equivalent
     * article has not been located. `IEME-RO` is from Regulamentul BNR nr. 11/2022. Every pattern
     * below is confirmed against real rows, and the ones that are not confirmed are simply absent:
     * `BD-PJR`, which Banca de Investiții și Dezvoltare has carried since November 2025, is left out
     * deliberately because no regulation we have read defines it. It resolves to `UNKNOWN`, which is
     * the honest answer until somebody reads the register and adds it here.
     *
     * A country with no entry resolves to `UNKNOWN` for every row rather than guessing — so widening
     * `BANKS_COUNTRIES` before writing that country's table is visible instead of silently wrong.
     */
    'kinds' => [
        'RO' => [
            // Bancă, persoană juridică română. Also covers mortgage banks and housing savings banks,
            // which the register keeps in their own sections under the same code.
            'BANK' => ['RB-PJR'],
            // Sucursală a unei instituții de credit străine, from a third country or a member state.
            'FOREIGN_BRANCH' => ['RB-PJS'],
            // Cooperativă de credit, and the casă centrală its network is affiliated to.
            'COOPERATIVE' => ['ROCC-CO', 'ROCC-CC'],
            // Instituție emitentă de monedă electronică. None reach the database today: they are a
            // different `CATEGORY` and the filter above drops them.
            'ELECTRONIC_MONEY' => ['IEME-RO'],
        ],
    ],

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

    /*
     * Each bank's logo, from files this installation puts in one folder — see
     * `Kern\TaxRomania\Banks\Logos\BankLogos`. None ship with the code: a logo is its bank's
     * trademark, and none is fetched from anywhere.
     *
     * Inside it, one folder per country, named by the bank's own country code, and in it one file per
     * bank named after its BIC (first eight characters) or LEI:
     * `<path>/RO/BTRLRO22.svg`. A bank with no file shows its initials.
     */
    'logos' => [
        'path' => env('BANKS_LOGOS_PATH') ?: storage_path('app/private/bank-logos'),
        // Per file. A logo is tens of kilobytes; a file this size is not one.
        'max_bytes' => 200 * 1024,
    ],

];
