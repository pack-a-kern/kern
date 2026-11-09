<?php

declare(strict_types=1);

/*
| The law watches this package declares to kern/legislation (`legislation.watches.declared`), added from
| `InvestmentsServiceProvider`: each programme's orders, searched on the portal by words of their title
| year by year from the programme's first, every so many hours. A new order is read as a law, and this
| package reads its issues from it then. No default: the values are in this package's .env.example.
*/

return [

    'investments.fidelis' => [
        'declared_by' => 'kern/investments',
        'search' => env('INVESTMENTS_FIDELIS_SEARCH'),
        'act_type' => env('INVESTMENTS_ORDERS_ACT_TYPE'),
        'title_pattern' => env('INVESTMENTS_FIDELIS_TITLE_PATTERN'),
        'first_year' => env('INVESTMENTS_FIDELIS_FIRST_YEAR'),
        'every_hours' => env('INVESTMENTS_ORDERS_WATCH_HOURS'),
    ],

    'investments.tezaur' => [
        'declared_by' => 'kern/investments',
        'search' => env('INVESTMENTS_TEZAUR_SEARCH'),
        'act_type' => env('INVESTMENTS_ORDERS_ACT_TYPE'),
        'title_pattern' => env('INVESTMENTS_TEZAUR_TITLE_PATTERN'),
        'first_year' => env('INVESTMENTS_TEZAUR_FIRST_YEAR'),
        'every_hours' => env('INVESTMENTS_ORDERS_WATCH_HOURS'),
    ],

];
