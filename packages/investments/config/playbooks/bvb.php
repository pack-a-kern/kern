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
 *  - The chart, its "6m" tab included, renders inside a `blob:https://www.bvb.ro/<uuid>` frame the page
 *    builds a few seconds after it loads (re-confirmed 2026-11-17). So the click names that frame — by
 *    the fragment the whole thing has in common, the per-load uuid dropped — and the interpreter waits
 *    for it to appear. On the main page the tab is not there, and the click waits out its timeout.
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
                    'frame' => 'blob:https://www.bvb.ro',
                    'capture' => ['url' => 'https://wapi.bvb.ro/api/history', 'query' => ['rs' => '1D']],
                    // The frame draws within ~5s on a page that charts; 15s is ample for that and the
                    // click's own fetch, and halves how long a page that never charts is waited out.
                    'timeout' => 15000,
                ],
            ],
            ['source' => true],
        ],
    ],
];
