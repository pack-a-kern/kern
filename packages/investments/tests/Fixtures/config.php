<?php

declare(strict_types=1);

/*
 * A whole, valid `config('investments')`, as `.env.example` sets it — the one the suite runs on, and the
 * one a test of a missing or wrong value changes a single key of.
 */
return [
    'bvb' => [
        'shares_url' => 'https://www.bvb.ro/shares',
        'bonds_url' => 'https://www.bvb.ro/bonds',
        'dividends_url' => 'https://www.bvb.ro/dividends',
        'instrument_url' => 'https://www.bvb.ro/instrument?s={symbol}',
        'page_refresh_days' => '7',
        'spacing_seconds' => '10',
        'timezone' => 'Europe/Bucharest',
    ],
    'orders' => [
        'act_type' => 'ORDIN',
        'title_patterns' => ['FIDELIS' => '/Programul FIDELIS/iu', 'TEZAUR' => '/în cadrul Programului Tezaur/iu'],
    ],
    'subscriptions' => [
        'page_url' => 'https://mfinante.gov.ro/subscriptions',
        'file_patterns' => ['FIDELIS' => '/FIDELIS[\w-]*\.xlsx/i', 'TEZAUR' => '/TEZAUR[\w-]*\.xlsx/i'],
    ],
    'write_chunk' => '500',
    'queue' => 'default',
    'api' => [
        'page_size' => '50',
        'max_span_days' => '1830',
        'reads_per_user' => ['max_attempts' => '120', 'decay_seconds' => '60'],
        'refreshes_per_user' => ['max_attempts' => '10', 'decay_seconds' => '3600'],
        'profile_refresh_hours' => '24',
    ],
    'processing_timeout_seconds' => '60',
];
