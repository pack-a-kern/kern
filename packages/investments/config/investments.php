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
    ],

];
