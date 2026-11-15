<?php

declare(strict_types=1);

/*
| The legislation this package requires to operate (`legislation.requirements.declared`, ADR 0027),
| added from `TaxRomaniaServiceProvider` the way watches are. The baseline the whole application
| rests on is two things, so it is declared as two requirements — the setup screen reads each need
| as its own step, and these are two steps of a different shape: the Cod Fiscal is one law brought
| in by its identifier, the minimum wage is a decision found by a watch and then a value approved.
|
| No default — the law identifier is this package's `FISCAL_PARAMETERS_LAW`, the one the fiscal
| parameters read against, so the two cannot disagree; the watch key is the one declared in this
| package's `watches.php`, so the need and the watch that satisfies it name the same thing.
*/

return [

    // The Cod Fiscal, present and in force: the law every tax is read from. One need — the law, by
    // the identifier the reader looks it up by (SourceDocument.external_identifier).
    'tax-romania.cod-fiscal' => [
        'declared_by' => 'kern/tax-romania',
        'tier' => 'required',
        'needs' => [
            'law' => env('FISCAL_PARAMETERS_LAW'),
        ],
    ],

    // This year's minimum wage, read and approved: the value every threshold is a multiple of. Two
    // needs — the Government decision that sets it, brought in by the watch this package declares
    // (`watches.php` key), and the value read from it approved for the active year.
    'tax-romania.minimum-wage' => [
        'declared_by' => 'kern/tax-romania',
        'tier' => 'required',
        'needs' => [
            'watch' => 'tax-romania.minimum_wage',
            'parameters' => 'active-year',
        ],
    ],

];
