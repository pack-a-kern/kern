<?php

declare(strict_types=1);

/*
| The legislation this package's features require (`legislation.requirements.declared`, ADR 0027),
| added from `InvestmentsServiceProvider` the way watches are. Bonds rest on the Ministry's orders
| being in the library — the programme whose order priced a bond is read from it (ADR 0026); shares
| do not, so these gate the bonds feature alone and nothing else.
|
| Both the Fidelis and the Tezaur orders gate `investments.bonds`, as two requirements naming the
| same feature. The readiness roll-up ANDs every requirement that gates a feature, so the feature is
| available only once both programmes' watches have read an order — a Tezaur holding is priced from
| the Tezaur order exactly as a Fidelis holding is from the Fidelis one (`TreasuryOrders`), so a
| library missing either cannot price every bond a person holds.
*/

return [

    'investments.bonds-fidelis' => [
        'declared_by' => 'kern/investments',
        'tier' => 'feature',
        'feature' => 'investments.bonds',
        // The Fidelis watch must have read at least one order.
        'needs' => ['watch' => 'investments.fidelis'],
    ],

    'investments.bonds-tezaur' => [
        'declared_by' => 'kern/investments',
        'tier' => 'feature',
        'feature' => 'investments.bonds',
        // The Tezaur watch must have read at least one order.
        'needs' => ['watch' => 'investments.tezaur'],
    ],

];
