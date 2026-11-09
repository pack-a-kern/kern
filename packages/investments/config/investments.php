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
     * How a programme's order is told among the laws read (ADR 0025 §5): its act type, and the pattern its
     * title matches — a delimited PCRE pattern, as `/…/u`. The search that finds the orders is the law
     * watches' (`config/watches.php`), which use these same values.
     */
    'orders' => [
        'act_type' => env('INVESTMENTS_ORDERS_ACT_TYPE'),
        'title_patterns' => [
            'FIDELIS' => env('INVESTMENTS_FIDELIS_TITLE_PATTERN'),
            'TEZAUR' => env('INVESTMENTS_TEZAUR_TITLE_PATTERN'),
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

    /*
     * The read endpoints (ADR 0025 §7): how many rows a page holds at most, how long a span of prices or
     * of payouts one answer covers at most, and each caller's budget — reads, and the profile read from
     * BVB on request, which reaches out and so has a budget of its own. A company's profile asked for
     * again while younger than its refresh age is the stored one.
     */
    'api' => [
        'page_size' => env('INVESTMENTS_API_PAGE_SIZE'),
        'max_span_days' => env('INVESTMENTS_API_MAX_SPAN_DAYS'),
        'reads_per_user' => [
            'max_attempts' => env('INVESTMENTS_API_READS_MAX_ATTEMPTS'),
            'decay_seconds' => env('INVESTMENTS_API_READS_DECAY_SECONDS'),
        ],
        'refreshes_per_user' => [
            'max_attempts' => env('INVESTMENTS_API_REFRESHES_MAX_ATTEMPTS'),
            'decay_seconds' => env('INVESTMENTS_API_REFRESHES_DECAY_SECONDS'),
        ],
        'profile_refresh_hours' => env('INVESTMENTS_PROFILE_REFRESH_HOURS'),
    ],

    // The queue an order is read for its issues on, once kern/legislation has read the order.
    'queue' => env('INVESTMENTS_QUEUE'),

    // How long the processing service may take to turn one of the Ministry of Finance's spreadsheets
    // into rows, in seconds.
    'processing_timeout_seconds' => env('INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'),

];
