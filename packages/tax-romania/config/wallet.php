<?php

declare(strict_types=1);

// Wallet form options (`BLUEPRINT.md` §6.4). Here rather than in the TypeScript that renders them,
// so the list an operator can change is not a literal inside a component.
return [

    'deposit' => [
        // Terms a bank actually sells, in months. The screen asks for a *term* and derives the
        // maturity date from it, because that is how a deposit is bought — "depozit la 6 luni", not
        // "ending 2027-02-12". It also removes a whole class of error: interest is
        // `principal × rate × days ÷ denominator`, and the days come from opened → maturity, so a
        // mistyped end date silently produces a wrong figure with nothing to flag it.
        'terms_months' => array_values(array_filter(array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', (string) env('WALLET_DEPOSIT_TERMS_MONTHS', '1,3,6,9,12,24,36')),
        ))),

        // Applied unless a deposit says otherwise. Not offered as a choice on the form: the
        // convention is set by the bank's own terms, not by the person recording the deposit, and a
        // wrong pick here changes the interest by roughly 1.4% (365 vs 360 as the denominator)
        // without looking wrong. It is shown read-only so the value is visible but not guessable at.
        //
        // ACTUAL/365 is the common convention for RON term deposits; confirm against the contract
        // for any deposit where the figure has to be exact, and change it per deposit through the
        // API, which still accepts every `DayCountConvention` case.
        'default_day_count' => env('WALLET_DEPOSIT_DEFAULT_DAY_COUNT', 'ACTUAL_365'),
    ],

];
