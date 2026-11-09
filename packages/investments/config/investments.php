<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Investments
|--------------------------------------------------------------------------
|
| Every value is read from the environment and has no default here (ADR 0025 §4): the values, and the
| sentence explaining each, are in this package's `.env.example`, which `php artisan kern:env` puts in
| the installation's one `.env`. Read only by `Kern\Investments\Config\InvestmentsConfig`, which
| validates each and fails naming the variable.
*/

return [

    // The Bucharest Stock Exchange's public pages, each read once a trading day after the close. Every
    // address must be https and on a host `config/sources.php` allows for `BVB`.
    'bvb' => [
        'shares_url' => env('INVESTMENTS_BVB_SHARES_URL'),
        'bonds_url' => env('INVESTMENTS_BVB_BONDS_URL'),
        'dividends_url' => env('INVESTMENTS_BVB_DIVIDENDS_URL'),
        // One instrument's page; `{symbol}` is replaced by its symbol.
        'instrument_url' => env('INVESTMENTS_BVB_INSTRUMENT_URL'),
        // Whose clock the times on those pages are — a price's "17:59" is that, there.
        'timezone' => env('INVESTMENTS_BVB_TIMEZONE'),
    ],

    /*
     * How the Ministry of Finance's orders for each programme are found on legislatie.just.ro (ADR 0025
     * §5): the words searched for in a title, the act type kept, the first year an order was issued, and
     * the pattern a title must match to be read as that programme's — so a law that only mentions it is
     * not taken for one. A delimited PCRE pattern, as `/…/u`.
     */
    'orders' => [
        'act_type' => env('INVESTMENTS_ORDERS_ACT_TYPE'),
        // An order of late December is published in January: while this year is younger than this many
        // days, last year is searched again too.
        'overlap_days' => env('INVESTMENTS_ORDERS_OVERLAP_DAYS'),
        'programmes' => [
            'FIDELIS' => [
                'search' => env('INVESTMENTS_FIDELIS_SEARCH'),
                'first_year' => env('INVESTMENTS_FIDELIS_FIRST_YEAR'),
                'title_pattern' => env('INVESTMENTS_FIDELIS_TITLE_PATTERN'),
            ],
            'TEZAUR' => [
                'search' => env('INVESTMENTS_TEZAUR_SEARCH'),
                'first_year' => env('INVESTMENTS_TEZAUR_FIRST_YEAR'),
                'title_pattern' => env('INVESTMENTS_TEZAUR_TITLE_PATTERN'),
            ],
        ],
    ],

    // The Ministry of Finance's page linking its two subscription lists, and the pattern each list's link
    // matches there — the file names carry their update date, so the links are read from the page.
    'subscriptions' => [
        'page_url' => env('INVESTMENTS_SUBSCRIPTIONS_PAGE_URL'),
        'file_patterns' => [
            'FIDELIS' => env('INVESTMENTS_SUBSCRIPTIONS_FIDELIS_FILE'),
            'TEZAUR' => env('INVESTMENTS_SUBSCRIPTIONS_TEZAUR_FILE'),
        ],
    ],

    // How many rows an import writes at once.
    'write_chunk' => env('INVESTMENTS_WRITE_CHUNK'),

    // The queue an order is read for its issues on, once kern/legislation has read the order.
    'queue' => env('INVESTMENTS_QUEUE'),

    // How long the processing service may take to turn one of the Ministry of Finance's spreadsheets
    // into rows, in seconds.
    'processing_timeout_seconds' => env('INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'),

];
