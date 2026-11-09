<?php

declare(strict_types=1);

/*
| The law watches this package declares to kern/legislation (`legislation.watches.declared`), added from
| `TaxRomaniaServiceProvider`. The Government's decision setting the minimum wage is a new act each time,
| not an amendment of one already read: watched for, a new one is read as a law without anyone adding it,
| and the wage read from it is proposed for approval as before. No default: the values are in this
| package's .env.example.
*/

return [

    'tax-romania.minimum_wage' => [
        'declared_by' => 'kern/tax-romania',
        'search' => env('TAX_MINIMUM_WAGE_WATCH_SEARCH'),
        'act_type' => env('TAX_MINIMUM_WAGE_WATCH_ACT_TYPE'),
        'title_pattern' => env('TAX_MINIMUM_WAGE_WATCH_TITLE_PATTERN'),
        'first_year' => env('TAX_MINIMUM_WAGE_WATCH_FIRST_YEAR'),
        'every_hours' => env('TAX_MINIMUM_WAGE_WATCH_HOURS'),
    ],

];
