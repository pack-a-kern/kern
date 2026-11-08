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
            explode(',', (string) env('WALLET_DEPOSIT_TERMS_MONTHS', '1,3,6,9,12,24,36,48,60,72,84')),
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

        // How many days before maturity a deposit's owners are told, once each — 0 is the day itself.
        // Sent by the daily `wallet.maturity_reminders` task, at the hour its cron sets.
        'reminder_days' => array_values(array_unique(array_filter(array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', (string) env('WALLET_DEPOSIT_REMINDER_DAYS', '30,7,1,0')),
        ), static fn (int $days): bool => $days >= 0))),

        // Adding deposits from a CSV file. The file is read in memory and never stored, so these are
        // the whole of what it can cost: a request body this large, and this many deposits opened in
        // one transaction — each with its own interest schedule and audit event.
        'import' => [
            'max_file_bytes' => (int) env('WALLET_DEPOSIT_IMPORT_MAX_FILE_BYTES', 1_048_576),
            'max_rows' => (int) env('WALLET_DEPOSIT_IMPORT_MAX_ROWS', 200),
        ],
    ],

];
