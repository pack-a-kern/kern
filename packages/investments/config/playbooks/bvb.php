<?php

declare(strict_types=1);

/*
 * An instrument's past prices, as its own page on bvb.ro loads them (ADR 0025 §5), through the browser
 * worker (`apps/browser-worker`).
 *
 * Observed on 2026-11-13 in the worker's Chromium:
 *
 *  - The page's chart asks `wapi.bvb.ro/api/history` for its prices, with a key the page itself carries.
 *    Asked directly, that address answers 401; the page is what BVB offers investors, so the page is
 *    what is opened, and what its chart fetches is what is kept.
 *  - The chart opens on a day's minutes (`rs=1`). Its "6m" tab asks the same address again by day
 *    (`rs=1D`) — after asking it once more by the minute, so the capture names `rs=1D`.
 *  - A browser that names itself "HeadlessChrome" is refused by BVB's firewall; the Browser card's
 *    engine and User-Agent are what every playbook goes out as (`PlaybookContext`).
 *
 * When the site changes, this file is what changes — no PHP, and no rebuild of the worker.
 */
return [
    'version' => 1,
    'name' => 'bvb',

    // The exchange's pages, and the address its chart reads from — checked on every request the page makes.
    'allow' => ['www.bvb.ro', 'bvb.ro', 'wapi.bvb.ro'],

    'sequences' => [
        // `{{ secret.url }}` is the instrument's page, from INVESTMENTS_BVB_INSTRUMENT_URL.
        'history' => [
            ['goto' => '{{ secret.url }}', 'timeout' => 60000],
            [
                'click' => [
                    'selector' => '[data-name="date-range-tab-6M"]',
                    'capture' => ['url' => 'https://wapi.bvb.ro/api/history', 'query' => ['rs' => '1D']],
                    'timeout' => 30000,
                ],
            ],
            ['source' => true],
        ],
    ],
];
