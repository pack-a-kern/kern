<?php

declare(strict_types=1);

/*
| The sources this package reads, added to `kern/legislation`'s registry (`sources.entries`) by
| `InvestmentsServiceProvider`, so that package never names a stock exchange (ADR 0025 §3).
|
| Like every entry there, which hosts may be fetched is code, reviewed with a change, never set from
| outside; the addresses read from them are configuration (`config/investments.php`). Tier D is
| BLUEPRINT.md §8.1's "official external values", BNR's tier. No `identify_as`: BVB goes out as the
| installation's default.
*/

return [

    'BVB' => [
        'trust_tier' => 'D',
        // The exchange's home page, as each source shows its own on the official sources screen.
        'base_url' => env('INVESTMENTS_BVB_BASE_URL'),
        'allowed_hosts' => ['www.bvb.ro', 'bvb.ro'],
        'enabled' => true,
        'priority' => 1,
    ],

];
