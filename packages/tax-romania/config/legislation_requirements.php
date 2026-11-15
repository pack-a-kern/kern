<?php

declare(strict_types=1);

/*
| The legislation this package requires to operate (`legislation.requirements.declared`, ADR 0027),
| added from `TaxRomaniaServiceProvider` the way watches are. Cod Fiscal present and in force, and
| this year's minimum wage approved, is the one baseline the whole application rests on: the reader
| looks the law up by this same identifier, and every threshold is a multiple of the wage. No
| default — the law identifier is this package's `FISCAL_PARAMETERS_LAW`, the one the fiscal
| parameters read against, so the two cannot disagree.
*/

return [

    'tax-romania.cod-fiscal' => [
        'declared_by' => 'kern/tax-romania',
        'tier' => 'required',
        'needs' => [
            // The law, by the identifier the reader looks it up by (SourceDocument.external_identifier).
            'law' => env('FISCAL_PARAMETERS_LAW'),
            // ...and this year's minimum wage read and approved — the value every threshold multiplies.
            'parameters' => 'active-year',
        ],
    ],

];
